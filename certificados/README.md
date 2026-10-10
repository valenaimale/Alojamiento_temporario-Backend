# Certificados de la base de datos en la nube

`aiven-ca.pem` es el certificado CA del servicio MySQL de Aiven (se descarga desde
"Información de conexión" → "CA certificate"). Se usa para verificar que la conexión
cifrada (SSL) es realmente con el servidor de Aiven.

Es **público**: no es una contraseña ni da acceso a la base. Por eso puede estar en el repo,
y el backend desplegado lo encuentra con `DB_SSL_CA=certificados/aiven-ca.pem`.
