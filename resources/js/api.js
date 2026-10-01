const CHUNK_SIZE = 512 * 1024;

const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function request(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            ...options.headers,
        },
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const errors = Object.values(body.errors ?? {}).flat();

        throw new Error(errors[0] ?? body.message ?? `Помилка сервера (${response.status})`);
    }

    return response.status === 204 ? null : response.json();
}

export async function uploadFile(file, onProgress) {
    const uploadId = uuid();
    const chunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

    for (let index = 0; index < chunks; index++) {
        const body = new FormData();
        body.append('upload_id', uploadId);
        body.append('index', index);
        body.append('chunk', file.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE), 'chunk');

        await request('/imports/chunks', { method: 'POST', body });
        onProgress(Math.round(((index + 1) / chunks) * 100));
    }

    const body = new FormData();
    body.append('upload_id', uploadId);
    body.append('name', file.name);
    body.append('chunks', chunks);

    return (await request('/imports', { method: 'POST', body })).data;
}

export async function fetchImports() {
    return (await request('/imports')).data;
}
