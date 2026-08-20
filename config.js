// Valeur par défaut locale. En production, deploy.sh régénère ce fichier.
window.RADIOPROTECTION_API_URL = window.RADIOPROTECTION_API_URL || 'api';
window.RADIOPROTECTION_SITE_URL = window.RADIOPROTECTION_SITE_URL || window.location.origin;
window.RADIOPROTECTION_SITE_NAME = window.RADIOPROTECTION_SITE_NAME || '';
window.RADIOPROTECTION_COPYRIGHT_OWNER = window.RADIOPROTECTION_COPYRIGHT_OWNER || window.RADIOPROTECTION_SITE_NAME || window.RADIOPROTECTION_SITE_URL;
// Absent en local: index.html masque la ligne "Maj le ..." si cette valeur n'est pas définie.
window.RADIOPROTECTION_LAST_DEPLOYED_AT = window.RADIOPROTECTION_LAST_DEPLOYED_AT || '';
