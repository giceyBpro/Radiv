    const API_BASE = (window.RADIOPROTECTION_API_URL || 'api').replace(/\/+$/, '');
    const SITE_URL = window.RADIOPROTECTION_SITE_URL || window.location.origin;
    const SITE_NAME = window.RADIOPROTECTION_SITE_NAME || (() => {
      try {
        return new URL(SITE_URL).hostname.replace(/^www\./, '');
      } catch (_) {
        return 'Nom du site';
      }
    })();
    const COPYRIGHT_OWNER = window.RADIOPROTECTION_COPYRIGHT_OWNER || SITE_NAME;
    const $ = (id) => document.getElementById(id);
    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
    // N'accepte qu'un schéma http(s): une valeur javascript: s'exécuterait au clic.
    const safeHttpUrl = (value) => {
      if (!value) return null;
      try {
        const parsed = new URL(String(value), window.location.origin);
        return (parsed.protocol === 'https:' || parsed.protocol === 'http:') ? parsed.href : null;
      } catch { return null; }
    };

    let isotopes = [];
    let cureOptionsByIsotope = {};
    let selectedCureCount = 1;
    let selectedCalculationMode = 'sfmn';
    let sfmnCalculatorUrl = '';
    let lastResult = null;
    let latestRequestId = 0;
    const audienceMeta = {
      conjoint_plus_60: { label: 'Contact avec le (la) conjoint(e) > 60 ans', condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 15 mSv' },
      conjoint_moins_60: { label: 'Contact avec le (la) conjoint(e) < 60 ans', condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 3 mSv' },
      conjointe_enceinte: { label: 'Contact avec la conjointe enceinte', condition: '8 h à 0,3 m et 3 h à 1 m,\nlimite 1 mSv' },
      transport_commun: { label: 'Transport en commun', condition: '3 h à 0,5 m,\nlimite 1 mSv' },
      enfant_moins_3_ans: { label: 'Contact avec un enfant (<3 ans)\nau retour à la maison', condition: '9 h à 1 m,\nlimite 1 mSv' },
      enfant_3_11_ans: { label: 'Contact avec un enfant (entre 3 et 11 ans)\nau retour à la maison', condition: '2 h à 0,5 m et 2 h à 1 m,\nlimite 1 mSv' },
      collegues_travail: { label: 'Contact avec des collègues de travail', condition: '6 h à 1 m,\nlimite 1 mSv' },
      scenario_utilisateur: { label: 'Scénario utilisateur', condition: 'h à m et h à 1 m,\nlimite mSv' }
    };

    function formatNumber(value, decimals = 2) {
      if (value === null || value === undefined || Number.isNaN(value)) return '-';
      return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: decimals }).format(value);
    }

    function setFooterMeta() {
      // window.RADIOPROTECTION_LAST_DEPLOYED_AT est la date réelle du dernier déploiement
      // (injectée par le script de déploiement), pas la date du jour de consultation.
      const lastDeployedAt = window.RADIOPROTECTION_LAST_DEPLOYED_AT;
      const lastUpdatedRow = $('lastUpdatedRow');
      if (lastDeployedAt) {
        const parsed = new Date(`${lastDeployedAt}T00:00:00`);
        $('lastUpdatedLabel').textContent = Number.isNaN(parsed.getTime())
          ? lastDeployedAt
          : parsed.toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' });
        lastUpdatedRow.style.display = '';
      } else {
        lastUpdatedRow.style.display = 'none';
      }
      $('siteNameBanner').textContent = SITE_NAME;
      $('copyrightText').textContent = `© ${new Date().getFullYear()} ${COPYRIGHT_OWNER}`;
    }

    function getNumberOrNull(id) {
      const raw = $(id).value.trim();
      if (raw === '') return null;
      const value = Number(raw);
      return Number.isFinite(value) ? value : null;
    }

    function roundUnit(value) {
      return Math.round(value);
    }

    async function fetchWithTimeout(url, options = {}, timeoutMs = 12000) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        return await fetch(url, { ...options, signal: controller.signal });
      } finally {
        clearTimeout(timer);
      }
    }

    function syncActivityFromMbq() {
      const mbq = getNumberOrNull('benignActivityMbq');
      if (mbq === null) {
        $('benignActivityMci').value = '';
        updateBenignDoseRateField();
        markFormDirty();
        return;
      }
      $('benignActivityMci').value = String(roundUnit(mbq / 37));
      updateBenignDoseRateField();
      markFormDirty();
    }

    function syncActivityFromMci() {
      const mci = getNumberOrNull('benignActivityMci');
      if (mci === null) {
        $('benignActivityMbq').value = '';
        updateBenignDoseRateField();
        markFormDirty();
        return;
      }
      $('benignActivityMbq').value = String(roundUnit(mci * 37));
      updateBenignDoseRateField();
      markFormDirty();
    }

    function updateBenignDoseRateField() {
      if ($('isotope').value !== 'iode131_benin') return;
      const mbq = getNumberOrNull('benignActivityMbq');
      const fixation = getNumberOrNull('benignFixation');
      if (!(mbq > 0) || !(fixation > 0)) {
        $('doseRate').value = '';
        return;
      }
      const fixationFraction = fixation / 100;
      const computed = (2.2 * mbq * fixationFraction) / 37;
      $('doseRate').value = String(Number(computed.toFixed(4)));
    }

    async function fetchConfig() {
      const url = `${API_BASE}/config`;
      const response = await fetchWithTimeout(url, {}, 12000);
      if (!response.ok) {
        throw new Error(`API indisponible (${response.status}) sur ${url}. Vérifiez que /api est joignable.`);
      }
      return response.json();
    }

    async function fetchCalculation(payload) {
      const url = `${API_BASE}/calculate`;
      const response = await fetchWithTimeout(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }, 15000);
      if (!response.ok) {
        throw new Error(`Erreur API (${response.status}) sur ${url}`);
      }
      return response.json();
    }

    function populateIsotopes(defaultIsotopeCode) {
      const labelWithSituationCodes = new Set(['iode131_0_fixation', 'iode131_5_fixation', 'iode131_25_fixation']);
      const visibleIsotopes = selectedCalculationMode === 'sfmn'
        ? isotopes.filter((iso) => iso.api_code !== 'non_defini')
        : isotopes;
      const fallbackCode = visibleIsotopes[0]?.api_code || '';
      const preferredCode = visibleIsotopes.some((iso) => iso.api_code === defaultIsotopeCode)
        ? defaultIsotopeCode
        : fallbackCode;
      $('isotope').innerHTML = '';
      visibleIsotopes.forEach((iso) => {
        const option = document.createElement('option');
        option.value = iso.api_code;
        option.textContent = labelWithSituationCodes.has(iso.api_code) && iso.situation
          ? `${iso.label} (${iso.situation})`
          : iso.label;
        if (iso.api_code === preferredCode) option.selected = true;
        $('isotope').appendChild(option);
      });
    }

    function getAllowedCureOptions(isotopeCode) {
      return cureOptionsByIsotope[isotopeCode] || [1];
    }

    function renderCureSelector(isotopeCode) {
      const selector = $('cureSelector');
      const allowed = getAllowedCureOptions(isotopeCode);
      selector.style.display = allowed.length > 1 ? 'flex' : 'none';
      if (!allowed.includes(selectedCureCount)) selectedCureCount = 1;
      selector.querySelectorAll('button[data-cure]').forEach((button) => {
        const cure = Number(button.dataset.cure);
        button.style.display = allowed.includes(cure) ? 'inline-block' : 'none';
        button.classList.toggle('active', cure === selectedCureCount);
      });
    }

    function toggleBenignRows(isBenign) {
      $('benignInlineBlock').hidden = !isBenign;
      $('doseRate').readOnly = isBenign;
      $('doseRate').placeholder = isBenign ? 'calculé automatiquement' : '';
      $('doseRateHelp').textContent = isBenign
        ? 'Débit calculé automatiquement : 2,2 × activité (MBq) × fixation (%) / 100 / 37.'
        : 'Saisie manuelle pour tous les radiopharmaceutiques sauf Iode-131-Bénin.';
      if (isBenign) {
        updateBenignDoseRateField();
      }
    }

    function getRequiredFieldIds(isotopeCode) {
      if (isotopeCode === 'iode131_benin') {
        return ['patientSize', 'benignActivityMbq', 'benignFixation'];
      }
      if (isotopeCode === 'non_defini') {
        return ['doseRate', 'patientSize', 'userPeriodDays'];
      }
      return ['doseRate', 'patientSize'];
    }

    function applyRequiredHighlight(isotopeCode) {
      const requiredIds = getRequiredFieldIds(isotopeCode);
      ['doseRate', 'patientSize', 'benignActivityMbq', 'benignFixation', 'userPeriodDays']
        .forEach((id) => {
          const stillEmpty = requiredIds.includes(id) && !(getNumberOrNull(id) > 0);
          $(id).classList.toggle('required-field', stillEmpty);
        });

      // Sur les pages qui le demandent (index.html) : mCi suit le même état que MBq (ils
      // sont synchronisés, cf. syncActivityFromMbq/Mci), et les 4 champs du scénario
      // utilisateur sont mis en évidence en rouge tant qu'ils sont vides — contrairement à
      // userPeriodDays, qu'on ne veut pas inciter à remplir (cf. #userPeriodDays en CSS).
      if (document.body.hasAttribute('data-field-style')) {
        const mbqEmpty = requiredIds.includes('benignActivityMbq') && !(getNumberOrNull('benignActivityMbq') > 0);
        $('benignActivityMci').classList.toggle('required-field', mbqEmpty);
        ['userHours1', 'userDistance1', 'userHours2', 'userLimit'].forEach((id) => {
          $(id).classList.toggle('required-field', !(getNumberOrNull(id) > 0));
        });
      }

      if (isotopeCode === 'non_defini') {
        $('optionalDetails').open = true;
      }
      renderCureSelector(isotopeCode);
    }

    function buildFriendlyErrors(selected) {
      const code = selected?.api_code || $('isotope').value;
      const missingLabels = [];
      const labels = {
        doseRate: 'Débit de dose à 1 m',
        patientSize: 'Taille du patient',
        benignActivityMbq: 'Activité administrée (MBq)',
        benignFixation: 'Taux de fixation (%)',
        userPeriodDays: 'Période effective (j)'
      };
      getRequiredFieldIds(code).forEach((id) => {
        const value = getNumberOrNull(id);
        if (!(value > 0)) missingLabels.push(labels[id]);
      });

      if (!missingLabels.length) return [];
      const isotopeLabel = selected?.label || code;
      return [`Pour « ${isotopeLabel} », merci de renseigner : ${missingLabels.join(', ')}.`];
    }

    function buildPayload() {
      const isotopeCode = $('isotope').value;
      const allowedCures = getAllowedCureOptions(isotopeCode);
      if (!allowedCures.includes(selectedCureCount)) {
        selectedCureCount = 1;
        renderCureSelector(isotopeCode);
      }
      return {
        calculation_mode: selectedCalculationMode || 'sfmn',
        isotope_code: isotopeCode,
        dose_rate: isotopeCode === 'iode131_benin' ? null : getNumberOrNull('doseRate'),
        patient_size_cm: getNumberOrNull('patientSize'),
        user_period_days: getNumberOrNull('userPeriodDays'),
        user_hours_1: getNumberOrNull('userHours1'),
        user_distance_1: getNumberOrNull('userDistance1'),
        user_hours_2: getNumberOrNull('userHours2'),
        user_limit: getNumberOrNull('userLimit'),
        benign_activity_mbq: getNumberOrNull('benignActivityMbq'),
        benign_fixation_pct: getNumberOrNull('benignFixation'),
        cure_count: selectedCureCount
      };
    }

    function hasAllRequiredFields(isotopeCode) {
      return getRequiredFieldIds(isotopeCode).every((id) => {
        const value = getNumberOrNull(id);
        return value !== null && value > 0;
      });
    }

    function clearDisplayedResults() {
      lastResult = null;
      $('effectiveDaysText').textContent = 'Période effective retenue en jours : -';
      $('effectiveHoursText').textContent = 'Période effective retenue en heures : -';
      $('resultsBody').innerHTML = '';
      $('errorBox').style.display = 'none';
      $('errorBox').textContent = '';
      $('durationHead').innerHTML = 'Durée restriction<br>(jours)';
      const printBtn = $('printBtn');
      if (printBtn.hasAttribute('data-show-after-calc')) printBtn.hidden = true;
    }

    function setLoadingState(isLoading) {
      $('calculateBtn').disabled = isLoading;
      $('loadingIndicator').classList.toggle('active', isLoading);
    }

    function markFormDirty() {
      latestRequestId += 1;
      setLoadingState(false);
      clearDisplayedResults();
      applyRequiredHighlight($('isotope').value);
    }

    function buildUserScenarioCondition() {
      const h1 = getNumberOrNull('userHours1');
      const d1 = getNumberOrNull('userDistance1');
      const h2 = getNumberOrNull('userHours2');
      const limit = getNumberOrNull('userLimit');
      if (h1 !== null && d1 !== null && h2 !== null && limit !== null) {
        return `${h1} h à ${d1} m et ${h2} h à 1 m,\nlimite ${limit} mSv`;
      }
      return audienceMeta.scenario_utilisateur.condition;
    }

    function hasUserScenarioDefined() {
      const h1 = getNumberOrNull('userHours1');
      const d1 = getNumberOrNull('userDistance1');
      const h2 = getNumberOrNull('userHours2');
      const limit = getNumberOrNull('userLimit');
      return h1 !== null && d1 !== null && h2 !== null && limit !== null;
    }

    function syncUiForSelectedIsotope() {
      const selectedCode = $('isotope').value;
      const selected = isotopes.find((iso) => iso.api_code === selectedCode);
      const isBenign = selectedCode === 'iode131_benin';
      toggleBenignRows(isBenign);
      applyRequiredHighlight(selectedCode);
      $('referenceText').textContent = selected?.reference || '-';
      $('remarkText').textContent = selected?.remark || '-';
      $('effectivePeriodLabel').textContent = selectedCode === 'non_defini'
        ? 'Période effective (j) (obligatoire à renseigner dans ce cas)'
        : 'Période effective (j) imposée par l’utilisateur (laisser vide sinon)';
    }

    function renderFromResult(result) {
      lastResult = result;
      const printBtn = $('printBtn');
      if (printBtn.hasAttribute('data-show-after-calc')) printBtn.hidden = false;
      const selected = result.selected;
      selectedCalculationMode = result.calculation_mode || selectedCalculationMode || 'sfmn';
      renderCalculationModeSelector();
      const isBenign = selected.api_code === 'iode131_benin';
      toggleBenignRows(isBenign);
      applyRequiredHighlight(selected.api_code);
      const fallbackDoseRate = selected.api_code === 'iode131_benin' ? null : getNumberOrNull('doseRate');
      const displayDoseRate = result.computed_dose_rate ?? fallbackDoseRate;
      if (isBenign) {
        if (result.computed_dose_rate === null || result.computed_dose_rate === undefined) {
          $('doseRate').value = $('doseRate').value || '';
        } else {
          $('doseRate').value = String(Number(result.computed_dose_rate.toFixed(4)));
        }
      }
      $('referenceText').textContent = selected.reference || '-';
      $('remarkText').textContent = selected.remark || '-';
      renderSourceForMode(result);
      selectedCureCount = Number(result.cure_count || selectedCureCount || 1);
      renderCureSelector(selected.api_code);
      $('durationHead').innerHTML = selectedCureCount > 1
        ? 'Durée restriction après chaque cure<br>(jours)'
        : 'Durée restriction<br>(jours)';

      $('effectivePeriodLabel').textContent = selected.api_code === 'non_defini'
        ? 'Période effective (j) (obligatoire à renseigner dans ce cas)'
        : 'Période effective (j) imposée par l’utilisateur (laisser vide sinon)';

      if (result.effective_days !== null && Number.isFinite(result.effective_days)) {
        $('effectiveDaysText').textContent = `Période effective retenue en jours : ${formatNumber(result.effective_days, 2)}`;
        $('effectiveHoursText').textContent = `Période effective retenue en heures : ${formatNumber(result.effective_hours, 2)}`;
      } else {
        $('effectiveDaysText').textContent = 'Période effective retenue en jours : -';
        $('effectiveHoursText').textContent = 'Période effective retenue en heures : -';
      }

      const errorBox = $('errorBox');
      const sfmnVerbose = (() => {
        if (!(result.sfmn_debug && result.sfmn_debug.enabled)) return null;
        const debug = result.sfmn_debug || {};
        const parsing = debug.parsing || {};
        const filtered = {
          enabled: true,
          requests: debug.requests || [],
          parsing: {
            response_html_excerpt: parsing.response_html_excerpt || parsing.result_html_excerpt || null,
            response_html: parsing.response_html || parsing.result_html || null,
            parsed_summary: parsing.parsed_summary || null,
            selected_radiopharmaceutical: parsing.selected_radiopharmaceutical || null,
            radiopharmaceutical_options: parsing.radiopharmaceutical_options || null,
            cookies_forwarded: parsing.cookies_forwarded || null
          }
        };
        return JSON.stringify(filtered, null, 2);
      })();
      const sfmnVerboseEsc = sfmnVerbose
        ? sfmnVerbose.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        : null;
      if (result.errors.length) {
        const friendlyErrors = buildFriendlyErrors(selected);
        const displayedErrors = (friendlyErrors.length ? friendlyErrors : result.errors).map((error) => (
          String(error || '').includes('SFMN task=process inaccessible')
            ? 'Le calcul SFMN est temporairement indisponible. Merci de réessayer dans quelques instants.'
            : error
        ));
        errorBox.style.display = 'block';
        errorBox.innerHTML = `<strong>Calcul impossible :</strong><br>${displayedErrors.map((e) => `- ${escapeHtml(e)}`).join('<br>')}`;
        if (sfmnVerboseEsc) {
          errorBox.innerHTML += `<br><br><strong>Information :</strong><br><pre style="white-space:pre-wrap;margin:6px 0 0 0;">${sfmnVerboseEsc}</pre>`;
        }
      } else {
        if (sfmnVerboseEsc) {
          errorBox.style.display = 'block';
          errorBox.innerHTML = `<strong>Information :</strong><br><pre style="white-space:pre-wrap;margin:6px 0 0 0;">${sfmnVerboseEsc}</pre>`;
        } else {
          errorBox.style.display = 'none';
          errorBox.textContent = '';
        }
      }

      const displayRows = Array.isArray(result.rows) && result.rows.length
        ? result.rows
        : Object.entries(result.recommendations_days || {}).map(([audienceCode, value]) => {
            let condition = audienceCode === 'scenario_utilisateur'
              ? buildUserScenarioCondition()
              : (audienceMeta[audienceCode]?.condition || '-');
            // result.rows (mode local) porte déjà cette précision de façon dynamique ; ce
            // repli (mode sfmn, où le texte n'est pas fourni par l'API) la reconstruit ici,
            // seulement si le nombre de cures le justifie — même règle que api/calculation.php.
            if (audienceCode === 'transport_commun' && result.cure_count > 1) {
              condition += ' (par cure, non cumulée)';
            }
            return {
              audience_code: audienceCode,
              label: audienceMeta[audienceCode]?.label || audienceCode,
              condition,
              value
            };
          });
      const filteredRows = hasUserScenarioDefined()
        ? displayRows
        : displayRows.filter((row) => row.audience_code !== 'scenario_utilisateur');

      $('resultsBody').innerHTML = filteredRows.map((row) => `
        <tr>
          <td class="scenario">${escapeHtml(row.label)}</td>
          <td class="value">${row.value === null ? '-' : escapeHtml(row.value)}</td>
          <td class="conditions">${escapeHtml(row.condition)}</td>
        </tr>
      `).join('');
    }

    function ensureCalculationModeButtons(availableModes) {
      const container = $('calculationModeSelector');
      if (container.querySelector('button[data-mode]')) return;
      const labels = { sfmn: 'Calcul SFMN', local: 'Calcul local' };
      availableModes.forEach((mode) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.mode = mode;
        button.textContent = labels[mode] || `Calcul ${mode}`;
        container.appendChild(button);
      });
    }

    function renderCalculationModeSelector() {
      $('calculationModeSelector').querySelectorAll('button[data-mode]').forEach((button) => {
        button.classList.toggle('active', button.dataset.mode === selectedCalculationMode);
      });
    }

    function renderSourceForMode(result = null) {
      const link = $('sourceLink');
      const meta = $('sourceMeta');
      if (selectedCalculationMode === 'sfmn') {
        const sfmnUrl = safeHttpUrl(result?.sfmn_source?.url || sfmnCalculatorUrl) || 'https://www.sfmn.org/';
        link.href = sfmnUrl;
        link.textContent = sfmnUrl;
        meta.textContent = '— source SFMN utilisée pour le calcul';
        return;
      }
      link.href = 'https://doi.org/10.1016/j.mednuc.2026.03.002';
      link.textContent = 'https://doi.org/10.1016/j.mednuc.2026.03.002';
      meta.textContent = '— Carlier et al., Médecine Nucléaire 2026;50:131-136 (groupe Radioprotection SFMN)';
    }

    async function renderResults() {
      const isotopeCode = $('isotope').value;
      if (!hasAllRequiredFields(isotopeCode)) {
        latestRequestId += 1;
        clearDisplayedResults();
        return;
      }

      const requestId = ++latestRequestId;
      clearDisplayedResults();
      setLoadingState(true);
      try {
        const result = await fetchCalculation(buildPayload());
        if (requestId !== latestRequestId) return;
        renderFromResult(result);
      } catch (error) {
        if (requestId !== latestRequestId) return;
        $('errorBox').style.display = 'block';
        $('errorBox').innerHTML = `<strong>Erreur réseau/API :</strong><br>- ${escapeHtml(error.message)}`;
      } finally {
        if (requestId === latestRequestId) {
          setLoadingState(false);
        }
      }
    }

    function bindEvents() {
      ['doseRate', 'patientSize', 'userPeriodDays', 'userHours1', 'userDistance1', 'userHours2', 'userLimit']
        .forEach((id) => $(id).addEventListener('input', () => {
          markFormDirty();
        }));

      $('isotope').addEventListener('input', () => {
        $('userPeriodDays').value = '';
        syncUiForSelectedIsotope();
        markFormDirty();
      });

      $('calculationModeSelector').querySelectorAll('button[data-mode]').forEach((button) => {
        button.addEventListener('click', () => {
          selectedCalculationMode = button.dataset.mode || 'sfmn';
          renderCalculationModeSelector();
          populateIsotopes($('isotope').value);
          syncUiForSelectedIsotope();
          renderSourceForMode();
          markFormDirty();
        });
      });

      $('cureSelector').querySelectorAll('button[data-cure]').forEach((button) => {
        button.addEventListener('click', () => {
          const cure = Number(button.dataset.cure);
          const allowed = getAllowedCureOptions($('isotope').value);
          if (!allowed.includes(cure)) return;
          selectedCureCount = cure;
          renderCureSelector($('isotope').value);
          markFormDirty();
        });
      });
      document.querySelectorAll('button[data-benign-fixation]').forEach((button) => {
        button.addEventListener('click', () => {
          $('benignFixation').value = button.dataset.benignFixation;
          updateBenignDoseRateField();
          markFormDirty();
        });
      });

      $('benignFixation').addEventListener('input', () => {
        updateBenignDoseRateField();
        markFormDirty();
      });
      $('benignActivityMbq').addEventListener('input', syncActivityFromMbq);
      $('benignActivityMci').addEventListener('input', syncActivityFromMci);
      $('calculateBtn').addEventListener('click', renderResults);
    }

    async function bootstrap() {
      setFooterMeta();
      try {
        const config = await fetchConfig();
        isotopes = config.isotopes || [];
        cureOptionsByIsotope = config.cure_options_by_isotope || {};
        sfmnCalculatorUrl = config.sfmn_calculator_url || '';
        const availableModes = config.calculation_modes || ['local', 'sfmn'];
        selectedCalculationMode = config.default_calculation_mode || 'sfmn';
        // Un seul mode de calcul disponible (SFMN désactivé côté serveur, SFMN_MODE_ENABLED=
        // false): le sélecteur n'a plus lieu d'être affiché plutôt que de montrer un choix
        // impossible. Sur les pages qui construisent leurs boutons dynamiquement (voir
        // ensureCalculationModeButtons), le DOM ne contient alors aucune mention du mode
        // non disponible, pas seulement un style masqué.
        if (availableModes.length > 1) {
          ensureCalculationModeButtons(availableModes);
          $('calculationModeField').hidden = false;
        } else {
          $('calculationModeField').hidden = true;
        }
        renderCalculationModeSelector();
        renderSourceForMode();
        populateIsotopes(config.default_isotope_code || isotopes[0]?.api_code);
        syncUiForSelectedIsotope();
        bindEvents();
      } catch (error) {
        $('errorBox').style.display = 'block';
        $('errorBox').innerHTML = '<strong>Service temporairement indisponible.</strong><br>- Veuillez réessayer dans quelques minutes.';
      }
    }

    function openPrintableVersion() {
      if (!lastResult) return;
      const payload = {
        generated_at: new Date().toISOString(),
        form: {
          isotope_label: $('isotope').selectedOptions[0]?.textContent || '',
          dose_rate: $('doseRate').value,
          patient_size_cm: $('patientSize').value,
          benign_activity_mbq: $('benignActivityMbq').value,
          benign_fixation_pct: $('benignFixation').value,
          user_period_days: $('userPeriodDays').value,
          user_hours_1: $('userHours1').value,
          user_distance_1: $('userDistance1').value,
          user_hours_2: $('userHours2').value,
          user_limit: $('userLimit').value,
          cure_count: selectedCureCount
        },
        result: lastResult
      };
      localStorage.setItem('radioprotection_print_payload', JSON.stringify(payload));
      window.open('/print', '_blank');
    }

    function openExplanatoryVersion() {
      if (!lastResult) return;
      const payload = {
        generated_at: new Date().toISOString(),
        form: {
          isotope_label: $('isotope').selectedOptions[0]?.textContent || '',
          dose_rate: $('doseRate').value,
          patient_size_cm: $('patientSize').value,
          benign_activity_mbq: $('benignActivityMbq').value,
          benign_fixation_pct: $('benignFixation').value,
          user_period_days: $('userPeriodDays').value,
          user_hours_1: $('userHours1').value,
          user_distance_1: $('userDistance1').value,
          user_hours_2: $('userHours2').value,
          user_limit: $('userLimit').value,
          cure_count: selectedCureCount
        },
        result: lastResult
      };
      localStorage.setItem('radioprotection_explain_payload', JSON.stringify(payload));
      window.open('/explain', '_blank');
    }

    $('printBtn').addEventListener('click', openPrintableVersion);
    const explainBtn = $('explainBtn');
    if (explainBtn) explainBtn.addEventListener('click', openExplanatoryVersion);

    bootstrap().catch((error) => {
      $('errorBox').style.display = 'block';
      $('errorBox').innerHTML = `<strong>Initialisation impossible :</strong><br>- ${escapeHtml(error.message)}`;
    });
