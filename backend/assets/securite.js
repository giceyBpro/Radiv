// Dessine le QR code de l'enregistrement TOTP dans <canvas id="qr" data-uri="otpauth://...">. Aucune donnée ne quitte la page.
(function () {
  var canvas = document.getElementById('qr');
  if (!canvas || typeof qrcode !== 'function') return;
  var qr = qrcode(0, 'M');
  qr.addData(canvas.getAttribute('data-uri') || '');
  qr.make();
  var n = qr.getModuleCount(); var cell = 5; var margin = 4 * cell; var size = n * cell + 2 * margin;
  canvas.width = size; canvas.height = size;
  var ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size); ctx.fillStyle = '#000';
  for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect(margin + c * cell, margin + r * cell, cell, cell);
}());
