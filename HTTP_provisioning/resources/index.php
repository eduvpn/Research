<?php

$ssl = openssl_x509_parse($_SERVER["SSL_CLIENT_CERT"]);

$tenantid = "{tenantId}";
$tenantid = str_replace("-", "", $tenantid);

// Openssl converts OID data to utf-8, we change that back to hex
$ext_value = $ssl['extensions']['1.2.840.113556.5.14'];
$hex = bin2hex($ext_value);

// We convert the oid to the tenant id using the operations that can be found in this
// blog post: https://call4cloud.nl/2022/07/the-tenantid-from-toronto/

// We remove the prefix 04 10 as that has nothing to do with our tenant id
$hex = substr($hex, 4);

// Declare variables
$x = 0;
$offset = 0;
$length = 8;
$reversedString = "";

// Reverse the first three parts of the oid (this is necessary
// please refer to the blog post above)
while($x < 3){
        $originalString = substr($hex, $offset, $length);
        $arrayWith2CharsPerElement = str_split($originalString, 2);
        $arrayWithReversedKeys = array_reverse($arrayWith2CharsPerElement);
        $newStringInReverseOrder = implode($arrayWithReversedKeys);

        $reversedString .= $newStringInReverseOrder;

        if($length == 8){
                $length = $length - 4;
        }
        if($offset == 0){
                $offset += 8;
        }
        else{
                $offset += 4;
        }
        $x++;
}
$ClientCertificateTenantId = $reversedString .= substr($hex, 16);

if ($ClientCertificateTenantId != $tenantid){
	http_response_code(403);
        echo 'error, the certificate tenant id is not equal to the tenant id of Intune';
        exit(1);
}

// We now know that the device certificate is part of Intune's correct tenant.
// Now we need to figure out of the managed device is currently part of Intune's tenant.


//////////////////////////////////////////////////////////////
// Get the Intune token so that we are allowed to do api calls
//////////////////////////////////////////////////////////////
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'https://login.microsoftonline.com/{tenantId}/oauth2/v2.0/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "client_id={applicationId}&scope=https%3A%2F%2Fgraph.microsoft.com%2F.default&client_secret={secretToken}&grant_type=client_credentials");

$headers = array();
$headers[] = 'Content-Type: application/x-www-form-urlencoded';
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$result = curl_exec($ch);
if (curl_errno($ch)) {
	http_response_code(502);
    	echo 'Error:' . curl_error($ch);
	exit(1);
}
curl_close($ch);

$jsonObject = json_decode($result,true);

$token = $jsonObject["access_token"];


//////////////////////////////////////////////////////
// Receive the managed device ids of the Intune tenant
//////////////////////////////////////////////////////


$url='https://graph.microsoft.com/v1.0/deviceManagement/managedDevices?$select=id';

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


$headers = array();
$headers[] = "Authorization: Bearer " . $token;
$headers[] = 'Content-Type: application/json';
$headers[] = 'Consistencylevel: eventual';
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$result = curl_exec($ch);
if (curl_errno($ch)) {
	http_response_code(502);
	echo 'Error:' . curl_error($ch);
	exit(1);
}
curl_close($ch);

// Accept the connection if the X509 common name
// string matches the managed device Id of Intune.

$jsonObject = json_decode($result,true);

$managedDeviceIds = $jsonObject["value"];

$flat = array_column($managedDeviceIds, 'id');

$boolean = True;

$managedId = $_SERVER["REMOTE_USER"];

$num = substr_count($managedId, '-');
if($num == 5){
        $managedId = substr(strstr($managedId, '-'), 1);
}

foreach ($flat as $value){
        if($value == $managedId){
                $boolean = False;
        }
}
if($boolean){
	http_response_code(403);
        echo 'error, the managed device id was not found in the Intune tenant';
        exit(1);
}


//Send an admin-api create request to eduVPN.
//eduVPN replies with a config which we will forward to the managed device.

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, 'https://{vpnDNS}/vpn-user-portal/admin/api/v1/create');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "user_id=" . $_POST["user_id"] . "&profile_id=" . $_POST["profile_id"]);
$headers = array();
$headers[] = 'Authorization: Bearer {adminApiToken}';
$headers[] = 'Content-Type: application/x-www-form-urlencoded';
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$config = curl_exec($ch);

$file = "/etc/eduVpnProvisioning/localDeviceIds.txt";
if (strpos(file_get_contents($file), $managedId) === false) {
        file_put_contents($file, $managedId . "\n", FILE_APPEND);
}


if (curl_errno($ch)) {
	http_response_code(502);
        echo 'Error:' . curl_error($ch);
        exit(1);
}
curl_close($ch);
echo $config;
?>
