const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

function encodeBase64Url(buffer) {
    return buffer.toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=/g, '');
}

// Generate P-256 (prime256v1) key pair
const { publicKey, privateKey } = crypto.generateKeyPairSync('ec', {
    namedCurve: 'prime256v1',
    publicKeyEncoding: {
        type: 'spki',
        format: 'der'
    },
    privateKeyEncoding: {
        type: 'pkcs8',
        format: 'der'
    }
});

// The public key in DER format for SPKI is 91 bytes.
// The actual uncompressed point starts at byte 26.
const pubKeyBuffer = publicKey.subarray(26);

// The private key in DER format.
// The actual 32-byte private scalar starts at byte 36.
const privKeyBuffer = privateKey.subarray(36, 36 + 32);

const vapidPublicKey = encodeBase64Url(pubKeyBuffer);
const vapidPrivateKey = encodeBase64Url(privKeyBuffer);

console.log('VAPID_PUBLIC_KEY=' + vapidPublicKey);
console.log('VAPID_PRIVATE_KEY=' + vapidPrivateKey);

const apiEnvFile = path.join(__dirname, '.env');
const frontEnvFile = path.join(__dirname, '../AGheal/.env');

fs.appendFileSync(apiEnvFile, `\n# WEB PUSH VAPID KEYS\nVAPID_PUBLIC_KEY=${vapidPublicKey}\nVAPID_PRIVATE_KEY=${vapidPrivateKey}\n`);
fs.appendFileSync(frontEnvFile, `\n# WEB PUSH VAPID PUBLIC KEY\nVITE_VAPID_PUBLIC_KEY=${vapidPublicKey}\n`);

console.log('Keys generated and mapped to .env files.');
