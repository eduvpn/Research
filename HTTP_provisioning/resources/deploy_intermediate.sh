#!/bin/sh

#
# Deploy an intermediate webserver and set up automatic VPN revocation
#

if ! [ "root" = "$(id -u -n)" ]; then
    echo "ERROR: ${0} must be run as root!"; exit 1
fi

printf "DNS name of the intermediate Web Server: "; read -r INTERMEDIATE_FQDN

printf "DNS name of eduVPN: "; read -r VPN_FQDN

printf "Directory (tenant) ID: "; read -r TENANT_ID

printf "Application (client) ID: "; read -r APPLICATION_ID

printf "Secret token of the registered application: "; read -r SECRET_TOKEN

printf "Token of the admin api from eduVPN: "; read -r ADMIN_API_TOKEN

WEB_FQDN=$(echo "${WEB_FQDN}" | tr '[:upper:]' '[:lower:]')

###############################################################################
# APACHE
###############################################################################

cp ./intermediate.example.conf "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"

cp ./MicrosoftIntuneRootCertificate.cer "/etc/ssl/certs/MicrosoftIntuneRootCertificate.cer"

mkdir -p "/var/www/${INTERMEDIATE_FQDN}/"
cp ./index.php "/var/www/${INTERMEDIATE_FQDN}/"

# update hostname
sed -i "s/vpn.example/${INTERMEDIATE_FQDN}/" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"
sed -i "s/vpn.example/${INTERMEDIATE_FQDN}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

# update vpn name
sed -i "s/{vpnDNS}/${VPN_FQDN}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

# update tenant id
sed -i "s/{tenantId}/${TENANT_ID}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

# update application id
sed -i "s/{applicationId}/${APPLICATION_ID}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

# update secret application token
sed -i "s/{secretToken}/${SECRET_TOKEN}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

# update admin api token
sed -i "s/{adminApiToken}/${ADMIN_API_TOKEN}/" "/var/www/${INTERMEDIATE_FQDN}/index.php"

###############################################################################
# CERTBOT
###############################################################################

a2ensite "${INTERMEDIATE_FQDN}"
systemctl restart apache2

certbot certonly -d "${INTERMEDIATE_FQDN}" --apache

sed -i "s/#SSLEngine/SSLEngine/" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"
sed -i "s/#Redirect/Redirect/" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"

sed -i "s|#SSLCertificateFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/cert.pem|SSLCertificateFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/cert.pem|" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"
sed -i "s|#SSLCertificateKeyFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/privkey.pem|SSLCertificateKeyFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/privkey.pem|" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"
sed -i "s|#SSLCertificateChainFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/chain.pem|SSLCertificateChainFile /etc/letsencrypt/live/${INTERMEDIATE_FQDN}/chain.pem|" "/etc/apache2/sites-available/${INTERMEDIATE_FQDN}.conf"

systemctl restart apache2

###############################################################################
# CRON
###############################################################################
mkdir -p -m 600 /etc/eduVpnProvisioning
cp -p ./revokeVpnConfigs "/etc/eduVpnProvisioning/revokeVpnConfigs"
touch /etc/eduVpnProvisioning/localDeviceIds.txt
chmod 666 /etc/eduVpnProvisioning/localDeviceIds.txt

sed -i "s/{applicationId}/${APPLICATION_ID}/" "/etc/eduVpnProvisioning/revokeVpnConfigs"
sed -i "s/{secretToken}/${SECRET_TOKEN}/" "/etc/eduVpnProvisioning/revokeVpnConfigs"
sed -i "s/{adminApiToken}/${ADMIN_API_TOKEN}/" "/etc/eduVpnProvisioning/revokeVpnConfigs"
sed -i "s/vpn.example/${VPN_FQDN}/" "/etc/eduVpnProvisioning/revokeVpnConfigs"
sed -i "s/{tenantId}/${TENANT_ID}/" "/etc/eduVpnProvisioning/revokeVpnConfigs"

cp ./eduVpnProvisioning /etc/cron.d/eduVpnProvisioning
