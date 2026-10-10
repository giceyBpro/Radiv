// Faux serveur SMTP de test (TLS direct sur 4650, STARTTLS sur 5870): enregistre la
// conversation reçue dans le fichier passé en 1er argument. Sert à vérifier api/lib/contact.php
// sans compte mail réel. Usage: node api/tests/fake-smtp.js <transcript.json> <key.pem> <cert.pem>
const net = require('net'); const tls = require('tls'); const fs = require('fs');
const [out, keyFile, certFile] = process.argv.slice(2);
const opts = { key: fs.readFileSync(keyFile), cert: fs.readFileSync(certFile) };
const log = [];
const save = () => fs.writeFileSync(out, JSON.stringify(log, null, 1));
function session(initial, mode, startTls) {
  let sock = initial; let authStep = 0; let buf = ''; let inData = false; let data = ''; const rec = { mode, commands: [], message: null };
  log.push(rec);
  const send = (s) => sock.write(s + '\r\n');
  const onData = (chunk) => {
    buf += chunk.toString('utf8');
    for (;;) {
      if (inData) {
        const end = buf.indexOf('\r\n.\r\n');
        if (end === -1) return;
        rec.message = buf.slice(0, end + 2); buf = buf.slice(end + 5); inData = false; save();
        send('250 2.0.0 queued'); continue;
      }
      const nl = buf.indexOf('\r\n'); if (nl === -1) return;
      const line = buf.slice(0, nl); buf = buf.slice(nl + 2);
      if (authStep > 0) { // identifiant puis mot de passe, en base64 (réponses à AUTH LOGIN)
        rec.commands.push(`[auth ${authStep === 1 ? 'user' : 'pass'}] ${Buffer.from(line, 'base64').toString()}`);
        send(authStep === 1 ? '334 UGFzc3dvcmQ6' : '235 ok'); authStep = authStep === 1 ? 2 : 0; continue;
      }
      rec.commands.push(line);
      if (/^EHLO/i.test(line)) { send('250-localhost'); if (startTls && !sock.encrypted) send('250-STARTTLS'); send('250 AUTH LOGIN'); }
      else if (/^STARTTLS/i.test(line)) {
        send('220 go ahead');
        const secure = new tls.TLSSocket(sock, { isServer: true, ...opts });
        sock.removeListener('data', onData); sock = secure; secure.on('data', onData); secure.on('error', () => {}); buf = '';
        return;
      }
      else if (/^AUTH LOGIN/i.test(line)) { send('334 VXNlcm5hbWU6'); authStep = 1; }
      else if (/^DATA/i.test(line)) { send('354 end with <CRLF>.<CRLF>'); inData = true; }
      else if (/^QUIT/i.test(line)) { send('221 bye'); sock.end(); save(); return; }
      else send('250 ok');
    }
  };
  sock.on('data', onData); sock.on('error', () => {});
  send('220 fake-smtp ready');
}
net.createServer((s) => session(s, 'starttls', true)).listen(5870, '127.0.0.1');
tls.createServer(opts, (s) => session(s, 'implicit', false)).listen(4650, '127.0.0.1');
console.log('fake smtp prêt');
