/**
 * webauthn.js — Real WebAuthn/Passkey implementation
 */
console.log('🔐 webauthn.js loading...');

const WebAuthnHelper = (() => {
    console.log('🔐 WebAuthnHelper initializing...');

    const RP_NAME = 'Quest Tracker';
    const getRpId = () => window.location.hostname;
    const STORE_KEY = 'webauthn_credential';

    const bufToBase64url = (buf) => {
        return btoa(String.fromCharCode(...new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    };

    const base64urlToBuf = (b64) => {
        b64 = b64.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        return Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    };

    const isSupported = () => {
        return !!(window.PublicKeyCredential &&
            navigator.credentials &&
            navigator.credentials.create &&
            navigator.credentials.get);
    };

    const isPlatformAvailable = async () => {
        if (!isSupported()) return false;
        try {
            return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        } catch {
            return false;
        }
    };

    const saveCredential = (credentialId, userId) => {
        localStorage.setItem(STORE_KEY, JSON.stringify({
            credentialId,
            userId,
            registeredAt: new Date().toISOString()
        }));
    };

    const loadCredential = () => {
        try {
            const raw = localStorage.getItem(STORE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };

    const clearCredential = () => {
        localStorage.removeItem(STORE_KEY);
    };

    const register = async (username = 'Player 1') => {
        console.log('🔐 register() called');
        if (!isSupported()) {
            return { success: false, error: 'WebAuthn not supported in this browser.' };
        }

        const userId = crypto.getRandomValues(new Uint8Array(16));
        const challenge = crypto.getRandomValues(new Uint8Array(32));

        const publicKeyOptions = {
            challenge,
            rp: { name: RP_NAME, id: getRpId() },
            user: { id: userId, name: username, displayName: username },
            pubKeyCredParams: [
                { type: 'public-key', alg: -7 },
                { type: 'public-key', alg: -257 },
            ],
            authenticatorSelection: {
                authenticatorAttachment: 'platform',
                userVerification: 'required',
                residentKey: 'preferred',
            },
            timeout: 60000,
            attestation: 'none',
        };

        try {
            const credential = await navigator.credentials.create({ publicKey: publicKeyOptions });
            if (!credential) {
                return { success: false, error: 'No credential returned.' };
            }
            const credentialId = bufToBase64url(credential.rawId);
            const userIdB64 = bufToBase64url(userId);
            saveCredential(credentialId, userIdB64);
            return { success: true, credentialId, userId: userIdB64 };
        } catch (err) {
            return { success: false, error: err.message };
        }
    };

    const authenticate = async () => {
        console.log('🔐 authenticate() called');
        if (!isSupported()) {
            return { success: false, error: 'WebAuthn not supported.' };
        }

        const challenge = crypto.getRandomValues(new Uint8Array(32));
        const publicKeyOptions = {
            challenge,
            rpId: getRpId(),
            userVerification: 'required',
            timeout: 60000,
        };

        try {
            const assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });
            if (!assertion) {
                return { success: false, error: 'No assertion returned.' };
            }
            return { success: true };
        } catch (err) {
            return { success: false, error: err.message };
        }
    };

    const authenticateQR = async () => {
        console.log('🔐 authenticateQR() called - QR mode');
        if (!isSupported()) {
            return { success: false, error: 'WebAuthn not supported.' };
        }

        const challenge = crypto.getRandomValues(new Uint8Array(32));
        const publicKeyOptions = {
            challenge,
            rpId: getRpId(),
            userVerification: 'required',
            mediation: 'conditional',
            timeout: 120000,
        };

        try {
            const assertion = await navigator.credentials.get({ publicKey: publicKeyOptions });
            if (!assertion) {
                return { success: false, error: 'No assertion returned.' };
            }
            return { success: true };
        } catch (err) {
            return { success: false, error: err.message };
        }
    };

    const isRegistered = () => !!loadCredential();
    const reset = () => clearCredential();

    console.log('🔐 WebAuthnHelper ready, authenticateQR exists:', typeof authenticateQR);

    return {
        isSupported,
        isPlatformAvailable,
        register,
        authenticate,
        authenticateQR,
        isRegistered,
        reset,
    };
})();

console.log('🔐 webauthn.js loaded, WebAuthnHelper.authenticateQR type:', typeof WebAuthnHelper.authenticateQR);