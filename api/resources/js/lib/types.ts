/**
 * Types du domaine partages par tous les ecrans.
 *
 * Miroir EXACT des API Resources Laravel (app/Http/Resources). Toutes les
 * reponses sont enveloppees : { "data": ... }.
 *
 * Verifie contre les Resources et le schema Postgres reels :
 *  - l'identifiant d'un document est un UUID (chaine), pas un entier ;
 *  - le statut d'une tache est `pending`, pas `open` ;
 *  - la priorite d'une tache est un ENTIER 1..3, pas un litteral ;
 *  - le montant est transmis en CHAINE (decimal:2) pour ne pas perdre de
 *    centimes en passant par un float JSON.
 */

/** App\Enums\DocumentStatus */
export type DocumentStatus = 'pending' | 'processing' | 'analyzed' | 'failed';

export const DOCUMENT_STATUS_LABELS: Record<DocumentStatus, string> = {
    pending: 'En attente',
    processing: 'Analyse en cours',
    analyzed: 'Analyse',
    failed: 'Echec',
};

/** App\Enums\DocumentSource */
export type DocumentSource = 'scanner' | 'shortcut' | 'import';

/** App\Enums\TodoStatus */
export type TodoStatus = 'pending' | 'done' | 'dismissed';

export const TODO_STATUS_LABELS: Record<TodoStatus, string> = {
    pending: 'A faire',
    done: 'Fait',
    dismissed: 'Ignore',
};

/** 1 = basse, 2 = normale, 3 = haute. */
export type TodoPriority = 1 | 2 | 3;

export const TODO_PRIORITY_LABELS: Record<TodoPriority, string> = {
    1: 'Basse',
    2: 'Normale',
    3: 'Haute',
};

/** App\Enums\CalendarAccountStatus */
export type CalendarAccountStatus = 'pending' | 'connected' | 'invalid_credentials';

/** App\Enums\CalendarSyncStatus */
export type CalendarSyncStatus = 'pending' | 'synced' | 'failed';

export interface Category {
    id: number;
    slug: string;
    name: string;
    color: string | null;
    icon: string | null;
    is_system: boolean;
    documents_count?: number;
}

export interface Tag {
    id: number;
    slug: string;
    name: string;
}

export interface DocumentPage {
    id: number;
    page_number: number;
    width: number | null;
    height: number | null;
    bytes: number | null;
    /** URL signee temporaire. */
    file_url: string;
    thumb_url: string | null;
    expires_at: string | null;
}

export interface TodoCalendarState {
    sync_status: CalendarSyncStatus;
    synced_at: string | null;
    last_error: string | null;
}

export interface Todo {
    id: string;
    title: string;
    details: string | null;
    due_at: string | null;
    all_day: boolean;
    priority: TodoPriority;
    status: TodoStatus;
    status_label: string;
    completed_at: string | null;
    document_id: string | null;
    document?: { id: string; title: string };
    calendar?: TodoCalendarState;
    created_at: string;
    updated_at: string;
}

export interface Document {
    id: string;
    title: string;
    status: DocumentStatus;
    status_label: string;
    /** true quand le document ne bougera plus (analyse ou echec). */
    is_terminal: boolean;
    source: DocumentSource;
    source_label: string;
    page_count: number;
    language: string | null;
    summary: string | null;
    doc_date: string | null;
    issuer: string | null;
    recipient: string | null;
    /** Chaine decimale, ex "1234.50". */
    total_amount: string | null;
    currency: string | null;
    reference: string | null;
    analysis_error: string | null;
    analyzed_at: string | null;
    category?: Category;
    pages?: DocumentPage[];
    todos?: Todo[];
    tags?: Tag[];
    todos_count?: number;
    raw_text?: string | null;
    created_at: string;
    updated_at: string;
}

export interface CalendarAccount {
    id: number;
    apple_id: string;
    status: CalendarAccountStatus;
    status_label: string;
    /** true quand la decouverte a abouti et que le calendrier est pret. */
    is_ready: boolean;
    discovered: boolean;
    calendar_name: string | null;
    last_sync_at: string | null;
    last_error: string | null;
    invalid_credentials: boolean;
    created_at: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    created_at: string;
    calendar_account?: CalendarAccount | null;
}

export interface IngestToken {
    token: string;
    expires_at: string;
    shortcut_url: string;
}

/** Formate un montant decimal transmis en chaine. */
export function formatAmount(amount: string | null, currency: string | null): string | null {
    if (!amount) return null;

    const value = Number(amount);
    if (!Number.isFinite(value)) return amount;

    return new Intl.NumberFormat('fr-FR', {
        style: currency ? 'currency' : 'decimal',
        currency: currency ?? undefined,
        minimumFractionDigits: 2,
    }).format(value);
}
