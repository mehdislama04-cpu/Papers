/**
 * Client API Papers.
 *
 * Auth = session Sanctum SPA par cookie (ARCHITECTURE.md §2), PAS de token
 * Bearer : ITP purge tout stockage inscriptible par script apres 7 jours sans
 * interaction, un token stocke cote client deconnecterait l'utilisateur.
 *
 * Trois regles non negociables :
 *  1. le cookie XSRF-TOKEN doit etre URL-DECODE avant d'aller dans
 *     X-XSRF-TOKEN — `fetch` ne le fait pas, contrairement a axios ;
 *  2. une 419 = jeton CSRF perime -> re-fetch de /sanctum/csrf-cookie puis UN
 *     seul rejeu. Jamais de deconnexion sur 419 ;
 *  3. X-Requested-With: XMLHttpRequest, sinon Laravel repond par une
 *     redirection HTML au lieu d'un 401 JSON.
 *
 * Toutes les reponses de l'API sont enveloppees : { "data": ... }.
 * Ces helpers renvoient le CORPS COMPLET (donc `.data`, et `.meta`/`.links`
 * pour les collections paginees), jamais un deballage implicite.
 */

const API_BASE = '/api';
const CSRF_COOKIE = 'XSRF-TOKEN';
const CSRF_ENDPOINT = '/sanctum/csrf-cookie';

export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';

/** Enveloppe standard d'une ressource unique. */
export interface Envelope<T> {
    data: T;
}

/** Enveloppe standard d'une collection paginee (API Resource Laravel). */
export interface PaginatedEnvelope<T> {
    data: T[];
    links?: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta?: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
}

export class ApiError extends Error {
    readonly status: number;
    readonly body: unknown;

    constructor(status: number, message: string, body: unknown) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.body = body;
    }

    get isUnauthenticated(): boolean {
        return this.status === 401;
    }

    get isOffline(): boolean {
        return this.status === 0;
    }
}

/** 422 Laravel : { message, errors: { champ: [messages] } }. */
export class ValidationError extends ApiError {
    readonly errors: Record<string, string[]>;

    constructor(message: string, errors: Record<string, string[]>, body: unknown) {
        super(422, message, body);
        this.name = 'ValidationError';
        this.errors = errors;
    }

    first(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

export interface RequestOptions {
    /** Corps : objet JSON, FormData (multipart), ou rien. */
    body?: unknown;
    /** Parametres de query string. Les valeurs nulles/undefined sont ignorees. */
    query?: Record<string, string | number | boolean | null | undefined>;
    headers?: Record<string, string>;
    signal?: AbortSignal;
}

/* -------------------------------------------------------------------------- */
/* Cookies                                                                     */
/* -------------------------------------------------------------------------- */

export function readCookie(name: string): string | null {
    const prefix = `${name}=`;
    for (const part of document.cookie.split('; ')) {
        if (part.startsWith(prefix)) {
            // Laravel pose la valeur URL-encodee. `fetch` ne decode rien :
            // sans ce decodeURIComponent, le '=' final devient '%3D' et
            // Laravel rejette le jeton -> 419 en boucle.
            return decodeURIComponent(part.slice(prefix.length));
        }
    }
    return null;
}

let csrfRequest: Promise<void> | null = null;

/**
 * Garantit la presence du cookie XSRF-TOKEN. Un seul appel concurrent :
 * au demarrage, plusieurs requetes partent souvent en parallele.
 */
export function fetchCsrfCookie(force = false): Promise<void> {
    if (!force && readCookie(CSRF_COOKIE) !== null) {
        return Promise.resolve();
    }

    csrfRequest ??= fetch(CSRF_ENDPOINT, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(() => undefined)
        .finally(() => {
            csrfRequest = null;
        });

    return csrfRequest;
}

/* -------------------------------------------------------------------------- */
/* URL                                                                         */
/* -------------------------------------------------------------------------- */

export function resolveUrl(
    path: string,
    query?: RequestOptions['query'],
): string {
    let url: string;

    if (/^https?:\/\//i.test(path)) {
        url = path;
    } else if (path.startsWith('/api/') || path === '/api' || path.startsWith('/sanctum/')) {
        url = path;
    } else {
        url = `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
    }

    if (query) {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(query)) {
            if (value === null || value === undefined || value === '') continue;
            params.set(key, String(value));
        }
        const qs = params.toString();
        if (qs) url += (url.includes('?') ? '&' : '?') + qs;
    }

    return url;
}

/* -------------------------------------------------------------------------- */
/* Requete                                                                     */
/* -------------------------------------------------------------------------- */

function buildInit(method: HttpMethod, options: RequestOptions): RequestInit {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        // Sans ca, Laravel renvoie une redirection HTML au lieu d'un 401 JSON.
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers,
    };

    if (options.signal) init.signal = options.signal;

    const token = readCookie(CSRF_COOKIE);
    if (token !== null && method !== 'GET') {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (options.body instanceof FormData) {
        // NE PAS poser Content-Type : le navigateur doit generer le boundary.
        init.body = options.body;
    } else if (options.body !== undefined && options.body !== null) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
    }

    return init;
}

async function parse(response: Response): Promise<unknown> {
    if (response.status === 204 || response.status === 205) return undefined;

    const type = response.headers.get('content-type') ?? '';
    if (!type.includes('json')) {
        const text = await response.text();
        return text.length > 0 ? text : undefined;
    }

    try {
        return await response.json();
    } catch {
        return undefined;
    }
}

function fail(response: Response, body: unknown): never {
    const asRecord = (body ?? {}) as Record<string, unknown>;
    const message =
        typeof asRecord.message === 'string' ? asRecord.message : `HTTP ${response.status}`;

    if (response.status === 422) {
        const errors = (asRecord.errors as Record<string, string[]>) ?? {};
        throw new ValidationError(message, errors, body);
    }

    throw new ApiError(response.status, message, body);
}

export async function request<T>(
    method: HttpMethod,
    path: string,
    options: RequestOptions = {},
): Promise<T> {
    const url = resolveUrl(path, options.query);
    const unsafe = method !== 'GET';

    if (unsafe) {
        await fetchCsrfCookie();
    }

    let response: Response;
    try {
        response = await fetch(url, buildInit(method, options));
    } catch (error) {
        if (options.signal?.aborted) throw error;
        throw new ApiError(0, 'Reseau indisponible', { cause: String(error) });
    }

    // 419 = Page Expired (jeton CSRF perime ou session recyclee).
    // On recharge le cookie et on rejoue UNE fois. Surtout pas de logout :
    // la session peut etre parfaitement valide.
    if (response.status === 419) {
        await fetchCsrfCookie(true);
        try {
            response = await fetch(url, buildInit(method, options));
        } catch (error) {
            if (options.signal?.aborted) throw error;
            throw new ApiError(0, 'Reseau indisponible', { cause: String(error) });
        }
    }

    const body = await parse(response);
    if (!response.ok) fail(response, body);

    return body as T;
}

/* -------------------------------------------------------------------------- */
/* Upload avec progression                                                     */
/* -------------------------------------------------------------------------- */

export interface UploadOptions {
    onProgress?: (sent: number, total: number) => void;
    signal?: AbortSignal;
    headers?: Record<string, string>;
}

/**
 * POST multipart avec progression reelle.
 *
 * `fetch` ne donne AUCUNE progression d'upload, et le streaming de corps
 * (ReadableStream + duplex:'half') n'est pas utilisable en prod sur Safari.
 * XHR est donc le seul chemin portable sur iOS.
 */
export function upload<T>(
    path: string,
    form: FormData,
    options: UploadOptions = {},
): Promise<T> {
    return fetchCsrfCookie().then(
        () =>
            new Promise<T>((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', resolveUrl(path), true);
                xhr.withCredentials = true;
                xhr.responseType = 'text';
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                const token = readCookie(CSRF_COOKIE);
                if (token !== null) xhr.setRequestHeader('X-XSRF-TOKEN', token);

                for (const [key, value] of Object.entries(options.headers ?? {})) {
                    xhr.setRequestHeader(key, value);
                }

                if (options.onProgress) {
                    const onProgress = options.onProgress;
                    xhr.upload.onprogress = (event) => {
                        if (event.lengthComputable) onProgress(event.loaded, event.total);
                    };
                }

                xhr.onload = () => {
                    let body: unknown;
                    try {
                        body = xhr.responseText ? JSON.parse(xhr.responseText) : undefined;
                    } catch {
                        body = xhr.responseText;
                    }

                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(body as T);
                        return;
                    }

                    const asRecord = (body ?? {}) as Record<string, unknown>;
                    const message =
                        typeof asRecord.message === 'string'
                            ? asRecord.message
                            : `HTTP ${xhr.status}`;

                    if (xhr.status === 422) {
                        reject(
                            new ValidationError(
                                message,
                                (asRecord.errors as Record<string, string[]>) ?? {},
                                body,
                            ),
                        );
                        return;
                    }

                    reject(new ApiError(xhr.status, message, body));
                };

                xhr.onerror = () => reject(new ApiError(0, 'Reseau indisponible', null));
                xhr.ontimeout = () => reject(new ApiError(0, 'Delai depasse', null));
                xhr.onabort = () => reject(new DOMException('Upload annule', 'AbortError'));

                options.signal?.addEventListener('abort', () => xhr.abort(), { once: true });
                xhr.send(form);
            }),
    );
}

/**
 * Rejoue un upload une fois sur 419 (jeton CSRF perime pendant que la requete
 * dormait dans la file offline).
 */
export async function uploadWithCsrfRetry<T>(
    path: string,
    form: FormData,
    options: UploadOptions = {},
): Promise<T> {
    try {
        return await upload<T>(path, form, options);
    } catch (error) {
        if (error instanceof ApiError && error.status === 419) {
            await fetchCsrfCookie(true);
            return upload<T>(path, form, options);
        }
        throw error;
    }
}

/* -------------------------------------------------------------------------- */

export const api = {
    get: <T>(path: string, options?: Omit<RequestOptions, 'body'>) =>
        request<T>('GET', path, options),
    post: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
        request<T>('POST', path, { ...options, body }),
    put: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
        request<T>('PUT', path, { ...options, body }),
    patch: <T>(path: string, body?: unknown, options?: Omit<RequestOptions, 'body'>) =>
        request<T>('PATCH', path, { ...options, body }),
    delete: <T>(path: string, options?: Omit<RequestOptions, 'body'>) =>
        request<T>('DELETE', path, options),
    upload: uploadWithCsrfRetry,
    csrf: fetchCsrfCookie,
};

export default api;
