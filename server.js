const http = require('http');
const fs = require('fs');
const path = require('path');
const net = require('net');
const tls = require('tls');
const crypto = require('crypto');
const readline = require('readline');
// Le calcul local (données + formalisme) est isolé dans calculation.js: fichier autonome,
// sans dépendance au reste du serveur, pour permettre un audit externe sans exposer le code
// réseau/sécurité/journalisation qui l'entoure ici.
const { isotopes, cureOptionsByIsotope, toNumberOrNull, getIsotope, normalizeCureCount, calculate } = require('./calculation.js');
function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const content = fs.readFileSync(filePath, 'utf8');
  content.split(/\r?\n/).forEach((line) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) return;
    const idx = trimmed.indexOf('=');
    if (idx === -1) return;
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if (value.startsWith('"') || value.startsWith("'")) {
      // Valeur entre guillemets: tout ce qui suit la guillemet fermante (commentaire compris)
      // est ignoré, sans risque de tronquer un '#' légitime à l'intérieur de la valeur.
      const quoteChar = value[0];
      const closingIdx = value.indexOf(quoteChar, 1);
      if (closingIdx !== -1) value = value.slice(1, closingIdx);
    } else {
      // Commentaire en fin de ligne (KEY=valeur  # commentaire): uniquement un '#' précédé
      // d'un espace, pour ne pas tronquer une valeur non quotée qui en contiendrait un
      // légitimement (ex. une URL avec un fragment #ancre, jamais précédé d'espace).
      const commentIdx = value.search(/\s#/);
      if (commentIdx !== -1) value = value.slice(0, commentIdx).trim();
    }
    if (!process.env[key]) process.env[key] = value;
  });
}
loadDotEnv(path.join(__dirname, '.runtime.env'));
loadDotEnv(path.join(__dirname, '.env'));
const parsedPort = Number(process.env.PORT || 3003);
const port = Number.isInteger(parsedPort) && parsedPort >= 0 && parsedPort <= 65535 ? parsedPort : 3003;
if (!Number.isInteger(parsedPort) || parsedPort < 0 || parsedPort > 65535) {
  console.warn(`PORT invalide (${process.env.PORT}); fallback sur 3003`);
}
// API_CORS_ORIGIN est le nom utilisé par .env.example / deploy_update.sh;
// CORS_ORIGIN reste accepté pour compatibilité.
const corsOrigin = process.env.API_CORS_ORIGIN || process.env.CORS_ORIGIN || '*';
const adminToken = process.env.ADMIN_TOKEN || '';
// Coupure complète des routes admin (mesures): désactivées, elles ne répondent plus du
// tout — même 404 générique que n'importe quelle route inexistante, pour ne rien
// distinguer d'un accès normal, avec ou sans jeton.
const ADMIN_MEASUREMENTS_ENABLED = String(process.env.ADMIN_MEASUREMENTS_ENABLED ?? 'true').toLowerCase() !== 'false';
// Proxies autorisés à définir X-Forwarded-For. Par défaut le reverse-proxy local
// (Apache/passenger). Mettre TRUSTED_PROXIES="" pour ignorer totalement l'en-tête.
const trustedProxies = (process.env.TRUSTED_PROXIES === undefined ? '127.0.0.1,::1' : process.env.TRUSTED_PROXIES)
  .split(',')
  .map((entry) => entry.trim())
  .filter(Boolean);
const logsDir = path.join(__dirname, 'logs');
const legacyLogsFile = path.join(logsDir, 'measurements.jsonl');
const geoIpCache = new Map();
const GEO_IP_CACHE_MAX = 500;
const contactRateMap = new Map();
const calculateRateMap = new Map();
const adminRateMap = new Map();
const LOGS_MAX_BYTES = Number(process.env.LOGS_MAX_BYTES || 50 * 1024 * 1024);
const CALCULATE_RATE_LIMIT = Number(process.env.CALCULATE_RATE_LIMIT || 60);
const recaptchaSecretKey = process.env.RECAPTCHA_SECRET_KEY || '';
const recaptchaSiteKey = process.env.RECAPTCHA_SITE_KEY || '';
const smtpHost = process.env.SMTP_HOST || '';
const smtpPort = Number(process.env.SMTP_PORT || 587);
const smtpSecure = String(process.env.SMTP_SECURE || 'false').toLowerCase() === 'true';
const smtpUser = process.env.SMTP_USER || '';
const smtpPass = process.env.SMTP_PASS || '';
const smtpFrom = process.env.SMTP_FROM || '';
const contactDest = process.env.CONTACT_DEST || '';
const SMTP_TIMEOUT_MS = Number(process.env.SMTP_TIMEOUT_MS || 15000);
const sfmnCalculatorUrl = process.env.SFMN_CALCULATOR_URL || '';
const sfmnDebugDefault = String(process.env.SFMN_DEBUG || 'false').toLowerCase() === 'true';
// Coupure complète du mode SFMN (calcul distant): quand désactivé, /api/config ne l'annonce
// plus (bouton de sélection masqué côté frontend), /api/calculate le refuse explicitement,
// et calculateSfmn() refuse également en interne par sécurité (défense en profondeur).
const SFMN_MODE_ENABLED = String(process.env.SFMN_MODE_ENABLED ?? 'true').toLowerCase() !== 'false';
if (!fs.existsSync(logsDir)) fs.mkdirSync(logsDir, { recursive: true });
migrateLegacyLogFile();
function emptyRecommendations() {
  return {
    conjoint_plus_60: null,
    conjoint_moins_60: null,
    conjointe_enceinte: null,
    transport_commun: null,
    enfant_moins_3_ans: null,
    enfant_3_11_ans: null,
    collegues_travail: null,
    scenario_utilisateur: null
  };
}
async function calculateSfmn(payload) {
  const selected = getIsotope(payload.isotope_code);
  if (!SFMN_MODE_ENABLED) {
    return {
      ok: false,
      calculation_mode: 'sfmn',
      selected,
      errors: ['Mode SFMN désactivé sur ce déploiement.'],
      error: { code: 'SFMN_MODE_DISABLED', message: 'SFMN_MODE_ENABLED=false côté serveur.' },
      recommendations_days: emptyRecommendations()
    };
  }
  // Le mode debug expose le HTML distant complet et les champs cachés du formulaire SFMN
  // (jeton CSRF compris): il ne doit dépendre que de la configuration serveur, jamais du payload.
  const debugEnabled = sfmnDebugDefault;
  const sfmnDebug = {
    enabled: debugEnabled,
    requests: [],
    parsing: {}
  };
  const wrapResult = (result) => (debugEnabled ? { ...result, sfmn_debug: sfmnDebug } : result);
  if (!sfmnCalculatorUrl) {
    return wrapResult({
      ok: false,
      calculation_mode: 'sfmn',
      selected,
      errors: ['Mode SFMN indisponible: URL distante non configurée.'],
      error: {
        code: 'SFMN_URL_NOT_CONFIGURED',
        message: 'SFMN_CALCULATOR_URL est vide côté backend.'
      },
      recommendations_days: emptyRecommendations()
    });
  }
  const sfmnMap = {
    iode131_0_fixation: 'Iodine-131-0%-uptake',
    iode131_5_fixation: 'Iodine-131-5%-uptake',
    iode131_25_fixation: 'Iodine-131-25%-uptake',
    iode131_benin: 'Iodine-131-Benign disease',
    psma_177lu: payload.cure_count === 6 ? 'PSMA-177Lu 6 cures' : payload.cure_count === 4 ? 'PSMA-177Lu 4 cures' : 'PSMA-177Lu',
    radium223: 'Radium-223',
    lutetium177_net: payload.cure_count === 4 ? 'NET 177Lu 4 cures' : 'NET 177Lu',
    microspheres_90y: 'Microspheres-90Y',
    microspheres_166ho: 'Microsphères-166Ho',
    lipiodol_131i: 'Lipiodol-131I',
    mibg_131i: 'MIBG 131I',
    synovectomie_90y: 'Synovectomy-90Y',
    synovectomie_186re: 'Synovectomy-186Re',
    synovectomie_169er: 'Synovectomy-169Er'
  };
  let sfmnRadiopharmaceutical = sfmnMap[selected.api_code];
  if (!sfmnRadiopharmaceutical) {
    return wrapResult({
      ok: false,
      calculation_mode: 'sfmn',
      selected,
      errors: [`Isotope non supporté par le mapping SFMN: ${selected.api_code}`],
      error: {
        code: 'SFMN_UNSUPPORTED_ISOTOPE',
        message: `Aucun mapping SFMN pour isotope_code=${selected.api_code}`
      },
      recommendations_days: emptyRecommendations()
    });
  }
  const toNum = (v) => toNumberOrNull(v);
  const userPeriod = toNum(payload.user_period_days);
  const userH1 = toNum(payload.user_hours_1);
  const userD1 = toNum(payload.user_distance_1);
  const userH2 = toNum(payload.user_hours_2);
  const userLimit = toNum(payload.user_limit);
  const userFields = [userH1, userD1, userH2, userLimit];
  const userComplete = userFields.every((v) => v !== null);
  const useScenarioAdapted = (userPeriod !== null) || userComplete;
  const benignActivity = toNum(payload.benign_activity_mbq);
  const benignFixation = toNum(payload.benign_fixation_pct);
  let pathology = 'nodule_hot_measured';
  let measuredEstimated = '0';
  let benignUptake = '';
  if (selected.api_code === 'iode131_benin' && benignFixation !== null) {
    measuredEstimated = '1';
    benignUptake = String(benignFixation);
    if (Math.abs(benignFixation - 25) < 0.001) pathology = 'mng_measured';
    else if (Math.abs(benignFixation - 30) < 0.001) pathology = 'graves_measured';
    else pathology = 'nodule_hot_measured';
  }
  const decodeHtml = (value) => String(value || '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&egrave;/g, 'è')
    .replace(/&eacute;/g, 'é')
    .replace(/&ecirc;/g, 'ê')
    .replace(/&agrave;/g, 'à')
    .replace(/&ocirc;/g, 'ô')
    .replace(/&icirc;/g, 'î')
    .replace(/&uuml;/g, 'ü')
    .replace(/&amp;/g, '&')
    .replace(/&#160;/g, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const normalize = (value) => decodeHtml(value)
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  const parseNumber = (value) => {
    const m = String(value || '').match(/([0-9]+(?:[.,][0-9]+)?)/);
    if (!m) return null;
    const n = Number(m[1].replace(',', '.'));
    return Number.isFinite(n) ? n : null;
  };
  const buildFormBody = (csrfName, hiddenFields = {}) => {
    const form = new URLSearchParams();
    Object.entries(hiddenFields).forEach(([name, value]) => {
      if (!name) return;
      form.set(name, value ?? '');
    });
    form.set('jform[radiopharmaceutical]', sfmnRadiopharmaceutical);
    form.set('jform[dose_rate]', selected.api_code === 'iode131_benin' ? '' : String(payload.dose_rate ?? ''));
    form.set('jform[patient_size]', String(payload.patient_size_cm ?? ''));
    form.set('jform[scenario_adapted_to_the_patient]', useScenarioAdapted ? '1' : '0');
    form.set('jform[effective_half_life]', String(userPeriod ?? 0));
    form.set('jform[duration_at_xm]', String(userH1 ?? ''));
    form.set('jform[distance_at_xm]', String(userD1 ?? ''));
    form.set('jform[duration_at_1m]', String(userH2 ?? ''));
    form.set('jform[distance_at_1m]', '1');
    form.set('jform[dosimetric_constraint]', String(userLimit ?? ''));
    form.set('jform[thyroide]', selected.api_code === 'iode131_benin' ? '1' : '0');
    form.set('jform[dysthyroidism_activity_administered]', String(benignActivity ?? ''));
    form.set('jform[pathology]', pathology);
    form.set('jform[measured_estimated]', measuredEstimated);
    form.set('jform[dysthyroidism_iodine_uptake]', benignUptake);
    form.set('jform[dysthyroidism_iodine_uptake_measured]', benignUptake);
    form.set('jform[dysthyroidism_iodine_uptake_estimated]', benignUptake);
    form.set('boxchecked', '0');
    form.set(csrfName, '1');
    if (debugEnabled) {
      sfmnDebug.requests.push({
        step: 'prepare_post_body',
        url: null,
        payload: {
          radiopharmaceutical: sfmnRadiopharmaceutical,
          dose_rate: selected.api_code === 'iode131_benin' ? '' : String(payload.dose_rate ?? ''),
          patient_size: String(payload.patient_size_cm ?? ''),
          scenario_adapted_to_the_patient: useScenarioAdapted ? '1' : '0',
          effective_half_life: String(userPeriod ?? 0),
          duration_at_xm: String(userH1 ?? ''),
          distance_at_xm: String(userD1 ?? ''),
          duration_at_1m: String(userH2 ?? ''),
          distance_at_1m: '1',
          dosimetric_constraint: String(userLimit ?? ''),
          thyroide: selected.api_code === 'iode131_benin' ? '1' : '0',
          dysthyroidism_activity_administered: String(benignActivity ?? ''),
          pathology,
          measured_estimated: measuredEstimated,
          dysthyroidism_iodine_uptake: benignUptake,
          dysthyroidism_iodine_uptake_measured: benignUptake,
          dysthyroidism_iodine_uptake_estimated: benignUptake,
          csrf_name: csrfName,
          hidden_forwarded: hiddenFields
        }
      });
    }
    return form.toString();
  };
  const parseSfmnResponse = (html) => {
    const recommendations = emptyRecommendations();
    const fallbackAudienceOrder = [
      'conjoint_plus_60',
      'conjoint_moins_60',
      'conjointe_enceinte',
      'transport_commun',
      'enfant_moins_3_ans',
      'enfant_3_11_ans',
      'collegues_travail'
    ];
    const labelMap = {
      'contact avec le (la) conjoint(e) > 60 ans': 'conjoint_plus_60',
      'contact avec le (la) conjoint(e) < 60 ans': 'conjoint_moins_60',
      'contact avec la conjointe enceinte': 'conjointe_enceinte',
      'transport en commun': 'transport_commun',
      'contact avec un enfant (<3 ans) au retour a la maison': 'enfant_moins_3_ans',
      'contact avec un enfant (entre 3 et 11 ans) au retour a la maison': 'enfant_3_11_ans',
      'contact avec des collegues de travail': 'collegues_travail',
      'contact with spouse > 60 years old': 'conjoint_plus_60',
      'contact with spouse < 60 years old': 'conjoint_moins_60',
      'contact with pregnant spouse': 'conjointe_enceinte',
      'public transportation': 'transport_commun',
      'contact with child (<3 years old) when back home': 'enfant_moins_3_ans',
      'contact with child (between 3 and 11 years old) when back home': 'enfant_3_11_ans',
      'contact with colleagues at work': 'collegues_travail'
    };
    const rows = [];
    let fallbackIndex = 0;
    const trRegex = /<tr>([\s\S]*?)<\/tr>/gi;
    for (const trMatch of html.matchAll(trRegex)) {
      const tr = trMatch[1];
      const tdRegex = /<td[^>]*>([\s\S]*?)<\/td>/gi;
      const cols = [...tr.matchAll(tdRegex)].map((m) => decodeHtml(m[1]));
      if (cols.length < 2) continue;
      if (normalize(cols[0]).includes("cas d'exemple")) continue;
      let code = labelMap[normalize(cols[0])];
      const periodDays = parseNumber(cols[1]);
      if (!code && periodDays !== null && fallbackIndex < fallbackAudienceOrder.length) {
        code = fallbackAudienceOrder[fallbackIndex];
      }
      if (code && periodDays !== null) recommendations[code] = periodDays;
      if (periodDays !== null) fallbackIndex += 1;
      rows.push({
        audience_code: code || null,
        label: cols[0],
        value: periodDays,
        condition: cols[2] || '-',
        limit: cols[3] || '-'
      });
    }
    const periodMatch = html.match(/Période effective imposée:\s*([0-9.,]+)\s*heures\s*=\s*([0-9.,]+)\s*jours/i);
    const effectiveHours = periodMatch ? parseNumber(periodMatch[1]) : null;
    const effectiveDays = periodMatch ? parseNumber(periodMatch[2]) : null;
    const doseMatch = html.match(/Débit de dose à 1m en sortie de chambre:\s*([0-9.,]+)/i);
    const computedDoseRate = doseMatch ? parseNumber(doseMatch[1]) : null;
    const curesMatch = html.match(/Nb cures:\s*([0-9]+)/i);
    const parsedCureCount = curesMatch ? parseNumber(curesMatch[1]) : 1;
    return { recommendations, rows, effectiveHours, effectiveDays, computedDoseRate, parsedCureCount };
  };
  try {
    const browserHeaders = {
      'User-Agent': 'Mozilla/5.0 (compatible; RadioprotectionBot/1.0; +https://example.org)',
      'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'Accept-Language': 'fr-FR,fr;q=0.9,en;q=0.8'
    };
    const rootResp = await fetch(sfmnCalculatorUrl, { method: 'GET', headers: browserHeaders });
    if (debugEnabled) {
      sfmnDebug.requests.push({
        step: 'sfmn_root_get',
        method: 'GET',
        url: sfmnCalculatorUrl,
        status: rootResp.status
      });
    }
    if (!rootResp.ok) {
      return wrapResult({
        ok: false,
        calculation_mode: 'sfmn',
        selected,
        errors: [`SFMN inaccessible (GET ${rootResp.status}).`],
        error: { code: 'SFMN_FETCH_FAILED', message: `GET SFMN a retourné ${rootResp.status}` },
        recommendations_days: emptyRecommendations()
      });
    }
    const rootHtml = await rootResp.text();
    const setCookies = typeof rootResp.headers.getSetCookie === 'function'
      ? rootResp.headers.getSetCookie()
      : (rootResp.headers.get('set-cookie') ? [rootResp.headers.get('set-cookie')] : []);
    const cookieHeader = setCookies
      .map((cookie) => String(cookie).split(';')[0].trim())
      .filter(Boolean)
      .join('; ');
    const optionMatches = [...rootHtml.matchAll(/<option[^>]*value="([^"]*)"[^>]*>([\s\S]*?)<\/option>/gi)]
      .map((m) => ({ value: decodeHtml(m[1]), text: decodeHtml(m[2]) }))
      .filter((o) => o.value && o.value !== '—' && o.value !== '-');
    const hiddenMatches = [...rootHtml.matchAll(/<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"[^>]*>/gi)];
    const hiddenFields = Object.fromEntries(hiddenMatches.map((m) => [decodeHtml(m[1]), decodeHtml(m[2])]));
    if (optionMatches.length) {
      const hasMappedValue = optionMatches.some((o) => o.value === sfmnRadiopharmaceutical);
      if (!hasMappedValue) {
        const targetNorm = normalize(selected.label || '');
        const byText = optionMatches.find((o) => normalize(o.text).includes(targetNorm));
        if (byText) sfmnRadiopharmaceutical = byText.value;
      }
    }
    if (debugEnabled) {
      sfmnDebug.parsing.root_html = rootHtml;
      sfmnDebug.parsing.form_page_html = rootHtml;
      sfmnDebug.parsing.radiopharmaceutical_options = optionMatches;
      sfmnDebug.parsing.selected_radiopharmaceutical = sfmnRadiopharmaceutical;
      sfmnDebug.parsing.cookies_forwarded = cookieHeader ? cookieHeader.split('; ').map((c) => c.split('=')[0]) : [];
      sfmnDebug.parsing.hidden_fields = hiddenFields;
    }
    if (optionMatches.length && !optionMatches.some((o) => o.value === sfmnRadiopharmaceutical)) {
      return wrapResult({
        ok: false,
        calculation_mode: 'sfmn',
        selected,
        errors: ['Le radiopharmaceutique demandé n’est pas reconnu par le formulaire SFMN distant.'],
        error: {
          code: 'SFMN_RADIOPHARMACEUTICAL_MISMATCH',
          message: `Valeur non trouvée dans les options SFMN: ${sfmnRadiopharmaceutical}`
        },
        recommendations_days: emptyRecommendations()
      });
    }
    const actionMatch = rootHtml.match(/<form[^>]*action="([^"]*option=com_evictionperiod[^"]*task=process[^"]*)"/i);
    const csrfMatch = rootHtml.match(/<input[^>]*type="hidden"[^>]*name="([a-f0-9]{32})"[^>]*value="1"/i);
    if (!actionMatch || !csrfMatch) {
      return wrapResult({
        ok: false,
        calculation_mode: 'sfmn',
        selected,
        errors: ['Impossible d’extraire action/token CSRF du formulaire SFMN.'],
        error: { code: 'SFMN_PARSE_FORM_FAILED', message: 'Action ou token CSRF introuvable.' },
        recommendations_days: emptyRecommendations()
      });
    }
    const actionUrl = new URL(actionMatch[1], sfmnCalculatorUrl).toString();
    const formBody = buildFormBody(csrfMatch[1], hiddenFields);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    const resp = await fetch(actionUrl, {
      method: 'POST',
      headers: {
        ...browserHeaders,
        'Content-Type': 'application/x-www-form-urlencoded',
        Referer: sfmnCalculatorUrl,
        Origin: new URL(sfmnCalculatorUrl).origin,
        'Upgrade-Insecure-Requests': '1',
        ...(cookieHeader ? { Cookie: cookieHeader } : {})
      },
      body: formBody,
      signal: controller.signal
    });
    clearTimeout(timer);
    if (debugEnabled) {
      sfmnDebug.requests.push({
        step: 'sfmn_process_post',
        method: 'POST',
        url: actionUrl,
        status: resp.status,
        redirected: Boolean(resp.redirected),
        final_url: resp.url || null
      });
    }
    if (!resp.ok) {
      return wrapResult({
        ok: false,
        calculation_mode: 'sfmn',
        selected,
        errors: [`SFMN task=process inaccessible (POST ${resp.status}).`],
        error: { code: 'SFMN_PROCESS_FAILED', message: `POST SFMN a retourné ${resp.status}` },
        recommendations_days: emptyRecommendations()
      });
    }
    const resultHtml = await resp.text();
    if (debugEnabled) {
      sfmnDebug.parsing.result_html_excerpt = resultHtml.trimStart().slice(0, 2000);
      sfmnDebug.parsing.result_html = resultHtml;
      sfmnDebug.parsing.response_html_excerpt = sfmnDebug.parsing.result_html_excerpt;
      sfmnDebug.parsing.response_html = resultHtml;
    }
    const parsed = parseSfmnResponse(resultHtml);
    if (debugEnabled) {
      sfmnDebug.parsing.parsed_summary = {
        parsed_rows_count: parsed.rows.length,
        effective_days: parsed.effectiveDays,
        effective_hours: parsed.effectiveHours,
        computed_dose_rate: parsed.computedDoseRate
      };
    }
    return wrapResult({
      ok: true,
      calculation_mode: 'sfmn',
      selected,
      cure_count: parsed.parsedCureCount || 1,
      cure_count_allowed: normalizeCureCount(selected, payload.cure_count).allowed,
      computed_dose_rate: parsed.computedDoseRate,
      effective_days: parsed.effectiveDays,
      effective_hours: parsed.effectiveHours,
      errors: [],
      recommendations_days: parsed.recommendations,
      sfmn_source: {
        url: sfmnCalculatorUrl,
        parsed_rows: parsed.rows
      }
    });
  } catch (_) {
    if (debugEnabled) {
      sfmnDebug.requests.push({
        step: 'sfmn_exception',
        error: 'exception_during_remote_call'
      });
    }
    return wrapResult({
      ok: false,
      calculation_mode: 'sfmn',
      selected,
      errors: ['Échec réseau vers SFMN.'],
      error: {
        code: 'SFMN_NETWORK_ERROR',
        message: 'Impossible de joindre/traiter la réponse SFMN.'
      },
      recommendations_days: emptyRecommendations()
    });
  }
}
function getClientIp(req) {
  const remote = req.socket?.remoteAddress || 'unknown';
  // X-Forwarded-For n'est cru que si la connexion vient réellement d'un proxy déclaré:
  // sinon n'importe qui peut usurper son IP (contournement du rate limit, empoisonnement des logs).
  if (!trustedProxies.length) return remote;
  if (!trustedProxies.includes(normalizeIpForLookup(remote))) return remote;
  const forwarded = req.headers['x-forwarded-for'];
  if (typeof forwarded === 'string' && forwarded.length) return forwarded.split(',')[0].trim();
  return remote;
}
function normalizeIpForLookup(ip) {
  if (!ip) return '';
  if (ip.startsWith('::ffff:')) return ip.slice(7);
  if (ip === '::1') return '127.0.0.1';
  return ip;
}
function isPrivateOrLocalIp(ip) {
  return ip === '127.0.0.1'
    || ip === '0.0.0.0'
    || ip.startsWith('10.')
    || ip.startsWith('192.168.')
    || /^172\.(1[6-9]|2\d|3[0-1])\./.test(ip)
    || ip.startsWith('fc')
    || ip.startsWith('fd')
    || ip === '::1';
}
async function resolveGeoFromIp(ip) {
  const normalized = normalizeIpForLookup(ip);
  if (!normalized) return null;
  if (isPrivateOrLocalIp(normalized)) {
    return { ip: normalized, scope: 'private_or_local' };
  }
  if (geoIpCache.has(normalized)) return geoIpCache.get(normalized);
  try {
    const providers = [
      {
        name: 'ipwho.is',
        url: `https://ipwho.is/${encodeURIComponent(normalized)}`,
        parse: (data) => (data?.success ? {
          ip: normalized,
          provider: 'ipwho.is',
          country: data.country || null,
          region: data.region || null,
          city: data.city || null,
          latitude: data.latitude ?? null,
          longitude: data.longitude ?? null
        } : null)
      },
      {
        name: 'ipapi.co',
        url: `https://ipapi.co/${encodeURIComponent(normalized)}/json/`,
        parse: (data) => (data && !data.error ? {
          ip: normalized,
          provider: 'ipapi.co',
          country: data.country_name || null,
          region: data.region || null,
          city: data.city || null,
          latitude: data.latitude ?? null,
          longitude: data.longitude ?? null
        } : null)
      },
      {
        name: 'ip-api.com',
        url: `https://ip-api.com/json/${encodeURIComponent(normalized)}`,
        parse: (data) => (data?.status === 'success' ? {
          ip: normalized,
          provider: 'ip-api.com',
          country: data.country || null,
          region: data.regionName || null,
          city: data.city || null,
          latitude: data.lat ?? null,
          longitude: data.lon ?? null
        } : null)
      }
    ];
    for (const provider of providers) {
      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 1600);
        const response = await fetch(provider.url, { signal: controller.signal });
        clearTimeout(timer);
        if (!response.ok) continue;
        const data = await response.json();
        const geo = provider.parse(data);
        if (geo) {
          if (geoIpCache.size >= GEO_IP_CACHE_MAX) geoIpCache.delete(geoIpCache.keys().next().value);
          geoIpCache.set(normalized, geo);
          return geo;
        }
      } catch {
        // provider suivant
      }
    }
    return null;
  } catch {
    return null;
  }
}
// Ne journalise que les champs attendus: le payload brut est contrôlé par l'appelant
// et gonflerait le fichier autant qu'il le souhaite.
const LOGGED_INPUT_FIELDS = [
  'calculation_mode', 'isotope_code', 'dose_rate', 'patient_size_cm',
  'user_period_days', 'user_hours_1', 'user_distance_1', 'user_hours_2', 'user_limit',
  'benign_activity_mbq', 'benign_fixation_pct', 'cure_count'
];
function sanitizeInputForLog(payload) {
  const safe = {};
  if (!payload || typeof payload !== 'object') return safe;
  for (const field of LOGGED_INPUT_FIELDS) {
    const value = payload[field];
    if (value === undefined || value === null) continue;
    safe[field] = typeof value === 'number' || typeof value === 'boolean'
      ? value
      : String(value).slice(0, 100);
  }
  return safe;
}
// Un seul measurements.jsonl grossit sans limite et readFileSync doit le charger et le
// parser entièrement en un seul appel synchrone: sur un fichier de plusieurs dizaines de
// Mo, ça bloque la boucle d'événements (donc toute l'API, y compris /health) pendant
// plusieurs secondes. Le découpage par mois borne la taille de chaque fichier, et la
// lecture en flux (readline) répartit le travail sur de nombreux petits ticks asynchrones
// au lieu d'un seul bloc.
function logFilePathForPeriod(year, month) {
  return path.join(logsDir, `measurements-${year}-${String(month).padStart(2, '0')}.jsonl`);
}
function listLogPeriods() {
  if (!fs.existsSync(logsDir)) return [];
  const re = /^measurements-(\d{4})-(\d{2})\.jsonl$/;
  const periods = [];
  for (const name of fs.readdirSync(logsDir)) {
    const m = name.match(re);
    if (m) periods.push({ year: Number(m[1]), month: Number(m[2]) });
  }
  periods.sort((a, b) => (b.year - a.year) || (b.month - a.month));
  return periods;
}
function rotateLogFileIfNeeded(file) {
  try {
    if (!fs.existsSync(file)) return;
    if (fs.statSync(file).size < LOGS_MAX_BYTES) return;
    fs.renameSync(file, `${file}.1`);
  } catch (error) {
    console.error('Erreur rotation log mesures:', error.message);
  }
}
function appendMeasurementLog(entry) {
  try {
    const date = new Date(entry.timestamp);
    const file = logFilePathForPeriod(date.getUTCFullYear(), date.getUTCMonth() + 1);
    rotateLogFileIfNeeded(file);
    fs.appendFileSync(file, `${JSON.stringify(entry)}\n`, 'utf8');
  } catch (error) {
    console.error('Erreur log mesures:', error.message);
  }
}
function readLogFileStream(file) {
  return new Promise((resolve, reject) => {
    if (!fs.existsSync(file)) return resolve([]);
    const rows = [];
    const rl = readline.createInterface({
      input: fs.createReadStream(file, { encoding: 'utf8' }),
      crlfDelay: Infinity
    });
    rl.on('line', (line) => {
      if (!line) return;
      try { rows.push(JSON.parse(line)); } catch { /* ligne corrompue ignorée */ }
    });
    rl.on('close', () => resolve(rows));
    rl.on('error', reject);
  });
}
async function readMeasurementLogs(year, month) {
  let files;
  if (year && month) {
    files = [logFilePathForPeriod(Number(year), Number(month))];
  } else if (year) {
    files = listLogPeriods()
      .filter((p) => p.year === Number(year))
      .map((p) => logFilePathForPeriod(p.year, p.month));
  } else {
    const now = new Date();
    files = [logFilePathForPeriod(now.getUTCFullYear(), now.getUTCMonth() + 1)];
  }
  const rows = [];
  for (const file of files) {
    rows.push(...await readLogFileStream(file));
    if (fs.existsSync(`${file}.1`)) rows.push(...await readLogFileStream(`${file}.1`));
  }
  return rows.sort((a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime());
}
// Migration ponctuelle: avant le découpage mensuel, tout était écrit dans un seul
// measurements.jsonl. Exécutée une fois au démarrage, avant server.listen(), donc sans
// impact sur des clients déjà connectés.
function migrateLegacyLogFile() {
  if (!fs.existsSync(legacyLogsFile)) return;
  try {
    const lines = fs.readFileSync(legacyLogsFile, 'utf8').split(/\r?\n/).filter(Boolean);
    for (const line of lines) {
      let entry;
      try { entry = JSON.parse(line); } catch { continue; }
      if (!entry || !entry.timestamp) continue;
      const date = new Date(entry.timestamp);
      if (Number.isNaN(date.getTime())) continue;
      const file = logFilePathForPeriod(date.getUTCFullYear(), date.getUTCMonth() + 1);
      fs.appendFileSync(file, `${JSON.stringify(entry)}\n`, 'utf8');
    }
    fs.renameSync(legacyLogsFile, `${legacyLogsFile}.migrated`);
    console.log('[MIGRATION] logs/measurements.jsonl scindé par mois (measurements-AAAA-MM.jsonl), archivé en .migrated.');
  } catch (error) {
    console.error('[MIGRATION] Échec migration logs legacy:', error.message);
  }
}
function csvEscape(value) {
  let text = String(value ?? '');
  // Neutralise l'injection de formules: Excel/LibreOffice interprètent = + - @ et les
  // caractères de contrôle en tête de cellule comme le début d'une formule.
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
  if (/[",\r\n]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}
function toCsv(rows) {
  const headers = ['timestamp', 'ip', 'ip_geo', 'isotope_code', 'dose_rate', 'patient_size_cm', 'effective_days', 'ok', 'errors', 'rows'];
  const body = rows.map((row) => [
    row.timestamp,
    row.ip,
    JSON.stringify(row.ip_geo || null),
    row.input?.isotope_code,
    row.input?.dose_rate,
    row.input?.patient_size_cm,
    row.result?.effective_days,
    row.result?.ok,
    (row.result?.errors || []).join(' | '),
    JSON.stringify(row.result?.rows || [])
  ].map(csvEscape).join(','));
  return [headers.join(','), ...body].join('\n');
}
function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
async function verifyRecaptcha(token, ip) {
  // Mode fermé: une clé absente ou mal orthographiée doit bloquer l'envoi,
  // sinon le formulaire de contact devient un relais d'envoi ouvert.
  if (!recaptchaSecretKey) {
    console.error('[CONTACT] RECAPTCHA_SECRET_KEY absente: envoi refusé.');
    return false;
  }
  const params = new URLSearchParams();
  params.set('secret', recaptchaSecretKey);
  params.set('response', token || '');
  if (ip) params.set('remoteip', ip);
  const response = await fetch('https://www.google.com/recaptcha/api/siteverify', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString()
  });
  if (!response.ok) return false;
  const data = await response.json();
  return data.success === true;
}
async function smtpSendMail({ replyTo, subject, html }) {
  if (!smtpHost || !smtpPort || !smtpUser || !smtpPass || !smtpFrom || !contactDest) {
    return { ok: false, error: 'Configuration SMTP incomplète (SMTP_* / CONTACT_DEST).' };
  }
  const fromMatch = smtpFrom.match(/<([^>]+)>/);
  const envelopeFrom = (fromMatch ? fromMatch[1] : smtpUser).trim();
  const messageId = `<${Date.now()}.${Math.random().toString(16).slice(2)}@radioprotection-riv.local>`;

  const connect = () => new Promise((resolve, reject) => {
    const onError = (err) => reject(err);
    if (smtpSecure) {
      const sock = tls.connect({ host: smtpHost, port: smtpPort, servername: smtpHost }, () => resolve(sock));
      sock.once('error', onError);
      return;
    }
    const sock = net.connect({ host: smtpHost, port: smtpPort }, () => resolve(sock));
    sock.once('error', onError);
  });

  // Réaffectée lors du passage en TLS via STARTTLS: readResponse/sendCmd lisent
  // toujours la valeur courante de cette liaison.
  let socket = await connect();
  socket.setEncoding('utf8');

  const readResponse = () => new Promise((resolve, reject) => {
    const active = socket;
    let buffer = '';
    const onData = (chunk) => {
      buffer += chunk;
      const lines = buffer.split(/\r?\n/).filter(Boolean);
      const last = lines[lines.length - 1] || '';
      if (/^\d{3} /.test(last)) {
        cleanup();
        resolve(lines);
      }
    };
    const onErr = (err) => { cleanup(); reject(err); };
    const onEnd = () => { cleanup(); reject(new Error('Connexion SMTP fermée')); };
    // Sans délai d'attente, un serveur qui accepte la connexion puis reste muet
    // suspend la requête HTTP indéfiniment.
    const timer = setTimeout(() => { cleanup(); reject(new Error('Délai SMTP dépassé')); }, SMTP_TIMEOUT_MS);
    const cleanup = () => {
      clearTimeout(timer);
      active.off('data', onData);
      active.off('error', onErr);
      active.off('end', onEnd);
    };
    active.on('data', onData);
    active.on('error', onErr);
    active.on('end', onEnd);
  });

  const sendCmd = async (cmd, expectedPrefix = '2') => {
    socket.write(`${cmd}\r\n`);
    const lines = await readResponse();
    const code = (lines[lines.length - 1] || '').slice(0, 1);
    if (code !== expectedPrefix) {
      throw new Error(`SMTP commande échouée (${cmd}): ${lines.join(' | ')}`);
    }
    return lines;
  };

  try {
    const greet = await readResponse();
    if (!(greet[greet.length - 1] || '').startsWith('2')) {
      throw new Error(`SMTP greeting invalide: ${greet.join(' | ')}`);
    }

    const ehlo = await sendCmd('EHLO radioprotection-riv.local', '2');

    if (!smtpSecure) {
      // TLS obligatoire: sans cette vérification, un attaquant en position d'intermédiaire
      // supprime l'annonce STARTTLS de la réponse EHLO et le mot de passe part en clair.
      if (!ehlo.join('\n').includes('STARTTLS')) {
        throw new Error('Le serveur SMTP n’annonce pas STARTTLS: envoi refusé (SMTP_SECURE=true requis pour une connexion TLS directe).');
      }
      await sendCmd('STARTTLS', '2');
      const plainSocket = socket;
      const secureSocket = tls.connect({ socket: plainSocket, servername: smtpHost });
      await new Promise((resolve, reject) => {
        secureSocket.once('secureConnect', resolve);
        secureSocket.once('error', reject);
      });
      secureSocket.setEncoding('utf8');
      // Bascule complète sur le socket TLS: fusionner les deux objets laisserait les
      // écritures suivantes (dont AUTH LOGIN) sur un socket dans un état indéfini.
      socket = secureSocket;
      await sendCmd('EHLO radioprotection-riv.local', '2');
    }

    await sendCmd(`AUTH LOGIN`, '3');
    await sendCmd(Buffer.from(smtpUser).toString('base64'), '3');
    await sendCmd(Buffer.from(smtpPass).toString('base64'), '2');

    await sendCmd(`MAIL FROM:<${envelopeFrom}>`, '2');
    await sendCmd(`RCPT TO:<${contactDest}>`, '2');
    await sendCmd('DATA', '3');

    const htmlDotSafe = html.replace(/\r?\n\./g, '\n..');
    const message = [
      `From: ${smtpFrom}`,
      `To: <${contactDest}>`,
      `Reply-To: ${replyTo}`,
      `Subject: [Radioprotection RIV] ${subject}`,
      `Date: ${new Date().toUTCString()}`,
      `Message-ID: ${messageId}`,
      `Return-Path: <${envelopeFrom}>`,
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit',
      '',
      htmlDotSafe,
      '.',
      ''
    ].join('\r\n');

    socket.write(message);
    const dataResponse = await readResponse();
    if (!(dataResponse[dataResponse.length - 1] || '').startsWith('2')) {
      throw new Error(`SMTP DATA échoué: ${dataResponse.join(' | ')}`);
    }

    await sendCmd('QUIT', '2');
    socket.end();
    return { ok: true };
  } catch (error) {
    try { socket.end(); } catch {}
    console.error('[SMTP] Erreur:', error.message);
    return { ok: false, error: "Erreur lors de l'envoi." };
  }
}

async function sendContactEmail({ email, message, ip, timestamp }) {
  const safeEmail = escapeHtml(email);
  const safeMessage = escapeHtml(message).replace(/\n/g, '<br>');
  const safeIp = escapeHtml(ip);

  const html = `
  <div style="font-family:Arial,Helvetica,sans-serif;background:#eef1f3;padding:20px;">
    <div style="max-width:700px;margin:0 auto;background:#ffffff;border-radius:10px;padding:20px;border:1px solid #d5dde1;">
      <h2 style="margin-top:0;color:#111;">Nouveau message - Dosimétrie RIV</h2>
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>Date</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">${timestamp}</td></tr>
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>IP</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">${safeIp}</td></tr>
        <tr><td style="padding:8px;border:1px solid #e3e7ea;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #e3e7ea;">${safeEmail}</td></tr>
      </table>
      <div style="margin-top:14px;padding:12px;background:#f4f7f8;border:1px solid #d5dde1;border-radius:6px;">${safeMessage}</div>
    </div>
  </div>`;

  return smtpSendMail({ replyTo: email.replace(/[\r\n]/g, ''), subject: 'Contact formulaire web', html });
}

function checkRateLimit(map, ip, maxPerMinute) {
  const now = Date.now();
  // Purge des fenêtres expirées: sans cela la table croît indéfiniment.
  if (map.size > 5000) {
    for (const [key, value] of map) {
      if (now > value.resetAt) map.delete(key);
    }
  }
  const entry = map.get(ip);
  if (!entry || now > entry.resetAt) { map.set(ip, { count: 1, resetAt: now + 60000 }); return true; }
  if (entry.count >= maxPerMinute) return false;
  entry.count++;
  return true;
}
function checkContactRateLimit(ip) {
  return checkRateLimit(contactRateMap, ip, 5);
}
function isAdminAuthorized(req) {
  if (!adminToken) return false;
  // Limite les tentatives (jeton correct ou non) avant même la comparaison, pour rendre
  // un brute-force impraticable; la réponse 401 déjà renvoyée aux appelants ne distingue
  // pas "jeton invalide" de "trop de tentatives", pour ne rien signaler à un attaquant.
  if (!checkRateLimit(adminRateMap, getClientIp(req), 10)) return false;
  const provided = req.headers['x-admin-token'];
  if (typeof provided !== 'string') return false;
  // Comparaison à temps constant pour ne pas divulguer le jeton octet par octet.
  const a = Buffer.from(provided, 'utf8');
  const b = Buffer.from(adminToken, 'utf8');
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}
function setCorsHeaders(res) {
  res.setHeader('Access-Control-Allow-Origin', corsOrigin);
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Admin-Token');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'no-referrer');
  res.setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
}
function sendJson(res, statusCode, data) {
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    // Réponses de données uniquement: rien ne doit y être chargé ni exécuté.
    'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'"
  });
  res.end(JSON.stringify(data));
}
function wantsHtml(req) {
  const accept = req.headers.accept || '';
  return accept.includes('text/html');
}
// L'API est appelée par des scripts (fetch/XHR: sendJson ci-dessus, inchangé) mais aussi
// parfois ouverte directement dans un navigateur (lien copié, faute de frappe). Dans ce cas
// mieux vaut une page lisible que le JSON brut {"error":"..."}."
function sendHtmlError(res, statusCode, statusLabel, message) {
  res.writeHead(statusCode, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'"
  });
  res.end(`<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>${statusCode} - API Dosimétrie RIV</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eef1f3;color:#111;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{max-width:520px;margin:24px;background:#fff;padding:28px;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);text-align:center}
h1{margin:0 0 8px;font-size:2rem;color:#c00000}
p{margin:8px 0}
.links{margin-top:20px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap}
a{color:#1637b8}
</style>
</head>
<body>
<main>
<h1>${statusCode}</h1>
<p><strong>${statusLabel}</strong></p>
<p>${message}</p>
<div class="links">
<a href="/api-fonctionnement.html">Documentation de l'API</a>
<a href="/">Retour à l'accueil</a>
</div>
</main>
</body>
</html>`);
}
const server = http.createServer((req, res) => {
  setCorsHeaders(res);
  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }
  const urlObj = new URL(req.url, `http://127.0.0.1:${port}`);
  const pathname = urlObj.pathname;
  if (req.method === 'GET' && pathname === '/api/config') {
    return sendJson(res, 200, {
      isotopes,
      default_isotope_code: 'iode131_25_fixation',
      cure_options_by_isotope: cureOptionsByIsotope,
      calculation_modes: SFMN_MODE_ENABLED ? ['local', 'sfmn'] : ['local'],
      default_calculation_mode: SFMN_MODE_ENABLED ? 'sfmn' : 'local',
      sfmn_calculator_url: SFMN_MODE_ENABLED ? sfmnCalculatorUrl : ''
    });
  }
  if (req.method === 'GET' && pathname === '/api/public-config') {
    return sendJson(res, 200, { recaptcha_site_key: recaptchaSiteKey ? recaptchaSiteKey : '' });
  }
  if (req.method === 'POST' && pathname === '/api/calculate') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; if (body.length > 65536) req.destroy(); });
    req.on('end', async () => {
      try {
        const ip = getClientIp(req);
        if (!checkRateLimit(calculateRateMap, ip, CALCULATE_RATE_LIMIT)) {
          return sendJson(res, 429, { ok: false, error: 'Trop de requêtes. Réessayez dans une minute.' });
        }
        const payload = body ? JSON.parse(body) : {};
        const requestedMode = String(payload.calculation_mode || (SFMN_MODE_ENABLED ? 'sfmn' : 'local')).toLowerCase();
        // SFMN désactivé côté serveur: on ne fait plus échouer la requête, on bascule sur le
        // calcul local (les deux formalismes sont désormais alignés) plutôt que d'imposer à
        // chaque client (RIS, scripts externes) de gérer lui-même ce repli.
        const calculationMode = requestedMode === 'sfmn' && !SFMN_MODE_ENABLED ? 'local' : requestedMode;
        const result = calculationMode === 'sfmn'
          ? await calculateSfmn(payload)
          : calculate(payload);
        const ipGeo = await resolveGeoFromIp(ip);
        appendMeasurementLog({
          timestamp: new Date().toISOString(),
          ip,
          ip_geo: ipGeo,
          input: sanitizeInputForLog(payload),
          result
        });
        sendJson(res, 200, result);
      } catch (error) {
        sendJson(res, 400, { error: 'JSON invalide' });
      }
    });
    return;
  }
  if (req.method === 'POST' && pathname === '/api/contact') {
    let body = '';
    req.on('data', (chunk) => { body += chunk; if (body.length > 65536) req.destroy(); });
    req.on('end', async () => {
      try {
        const payload = body ? JSON.parse(body) : {};
        const email = String(payload.email || '').trim();
        const message = String(payload.message || '').trim();
        const recaptchaTokenValue = String(payload.recaptcha_token || '').trim();
        const ip = getClientIp(req);
        if (!email || !message) {
          return sendJson(res, 400, { ok: false, error: 'email et message sont obligatoires.' });
        }
        if (!/^[^\s@\r\n]{1,64}@[^\s@\r\n]{1,255}$/.test(email)) {
          return sendJson(res, 400, { ok: false, error: 'Format email invalide.' });
        }
        if (!checkContactRateLimit(ip)) {
          return sendJson(res, 429, { ok: false, error: 'Trop de demandes. Réessayez dans une minute.' });
        }
        const recaptchaOk = await verifyRecaptcha(recaptchaTokenValue, ip);
        if (!recaptchaOk) {
          return sendJson(res, 400, { ok: false, error: 'Échec vérification reCAPTCHA.' });
        }
        const sent = await sendContactEmail({
          email,
          message,
          ip,
          timestamp: new Date().toISOString()
        });
        if (!sent.ok) {
          return sendJson(res, 500, { ok: false, error: 'Échec envoi email.' });
        }
        return sendJson(res, 200, { ok: true });
      } catch (error) {
        return sendJson(res, 400, { ok: false, error: 'JSON invalide' });
      }
    });
    return;
  }
  if (req.method === 'GET' && pathname === '/api/admin/measurements/periods' && ADMIN_MEASUREMENTS_ENABLED) {
    if (!isAdminAuthorized(req)) return sendJson(res, 401, { error: 'Unauthorized' });
    return sendJson(res, 200, { periods: listLogPeriods() });
  }
  if (req.method === 'GET' && pathname === '/api/admin/measurements' && ADMIN_MEASUREMENTS_ENABLED) {
    if (!isAdminAuthorized(req)) return sendJson(res, 401, { error: 'Unauthorized' });
    const year = urlObj.searchParams.get('year');
    const month = urlObj.searchParams.get('month');
    readMeasurementLogs(year, month)
      .then((rows) => sendJson(res, 200, { rows, year: year || null, month: month || null, total: rows.length }))
      .catch((error) => {
        console.error('Erreur lecture mesures:', error.message);
        sendJson(res, 500, { error: 'Erreur lecture des mesures.' });
      });
    return;
  }
  if (req.method === 'GET' && pathname === '/api/admin/measurements.csv' && ADMIN_MEASUREMENTS_ENABLED) {
    if (!isAdminAuthorized(req)) return sendJson(res, 401, { error: 'Unauthorized' });
    const year = urlObj.searchParams.get('year');
    const month = urlObj.searchParams.get('month');
    readMeasurementLogs(year, month)
      .then((rows) => {
        const csv = toCsv(rows);
        const label = year && month ? `${year}-${String(month).padStart(2, '0')}` : (year || 'periode');
        res.writeHead(200, {
          'Content-Type': 'text/csv; charset=utf-8',
          'Content-Disposition': `attachment; filename="mesures_${label}.csv"`
        });
        res.end(csv);
      })
      .catch((error) => {
        console.error('Erreur export mesures:', error.message);
        sendJson(res, 500, { error: 'Erreur lecture des mesures.' });
      });
    return;
  }
  if (req.method === 'GET' && pathname === '/health') {
    return sendJson(res, 200, { ok: true, time: new Date().toISOString() });
  }
  if (wantsHtml(req)) {
    return sendHtmlError(res, 404, 'Page introuvable', 'Cette adresse ne correspond à aucune ressource de l\'API RadIV.');
  }
  sendJson(res, 404, { error: 'Not found' });
});

// Poursuivre après une exception non capturée laisserait le process dans un état
// incohérent: on ferme proprement et le superviseur (monitor_node.sh / pm2) relance.
process.on('uncaughtException', (error) => {
  console.error('[FATAL] uncaughtException:', error);
  server.close(() => process.exit(1));
  setTimeout(() => process.exit(1), 5000).unref();
});

process.on('unhandledRejection', (reason) => {
  console.error('[FATAL] unhandledRejection:', reason);
});

server.listen(port, () => {
  console.log(`Radioprotection API listening on port ${port}`);
});
