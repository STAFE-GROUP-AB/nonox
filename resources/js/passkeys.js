/*
 * Passkeys in the browser (ADR-029). The server sends WebAuthn options as JSON
 * with binary fields in base64url; the browser API wants ArrayBuffers, and the
 * answer goes back as JSON with base64url again. Bundled by Vite, no CDN:
 * the content security policy allows scripts from NONOX only.
 */

export function base64urlToBuffer(value) {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

export function bufferToBase64url(buffer) {
    const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
    let binary = '';

    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function descriptors(list) {
    return (list ?? []).map((descriptor) => ({ ...descriptor, id: base64urlToBuffer(descriptor.id) }));
}

export function creationOptions(json) {
    return {
        ...json,
        challenge: base64urlToBuffer(json.challenge),
        user: { ...json.user, id: base64urlToBuffer(json.user.id) },
        excludeCredentials: descriptors(json.excludeCredentials),
    };
}

export function requestOptions(json) {
    return {
        ...json,
        challenge: base64urlToBuffer(json.challenge),
        allowCredentials: descriptors(json.allowCredentials),
    };
}

export function credentialToJson(credential) {
    const response = credential.response;
    const json = {
        id: credential.id,
        rawId: bufferToBase64url(credential.rawId),
        type: credential.type,
        response: { clientDataJSON: bufferToBase64url(response.clientDataJSON) },
        clientExtensionResults: typeof credential.getClientExtensionResults === 'function' ? credential.getClientExtensionResults() : {},
    };

    if (response.attestationObject) {
        json.response.attestationObject = bufferToBase64url(response.attestationObject);
        json.response.transports = typeof response.getTransports === 'function' ? response.getTransports() : [];
    } else {
        json.response.authenticatorData = bufferToBase64url(response.authenticatorData);
        json.response.signature = bufferToBase64url(response.signature);

        if (response.userHandle) {
            json.response.userHandle = bufferToBase64url(response.userHandle);
        }
    }

    return json;
}

export function supported() {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential === 'function' && window.isSecureContext === true;
}

/** A Swedish message for a failed or cancelled ceremony. */
export function errorMessage(error) {
    if (!supported()) {
        return 'Den här webbläsaren kan inte använda passkeys här. Använd en aktuell webbläsare och en säker anslutning (https).';
    }

    switch (error?.name) {
        case 'NotAllowedError':
            return 'Passkeyn användes inte. Avbröt du, eller tog tiden slut? Försök igen.';
        case 'InvalidStateError':
            return 'Den här enheten har redan en passkey för ditt konto.';
        case 'SecurityError':
            return 'Webbläsaren godtar inte adressen för passkeys. Kontrollera att du använder rätt adress till NONOX.';
        default:
            return 'Det gick inte att använda passkeyn. Försök igen.';
    }
}

export async function register(optionsJson) {
    const credential = await navigator.credentials.create({ publicKey: creationOptions(optionsJson) });

    return credentialToJson(credential);
}

export async function authenticate(optionsJson) {
    const credential = await navigator.credentials.get({ publicKey: requestOptions(optionsJson) });

    return credentialToJson(credential);
}

if (typeof window !== 'undefined') {
    window.NonoxPasskeys = { supported, register, authenticate, errorMessage };
}
