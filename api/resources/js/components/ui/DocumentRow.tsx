import { Link } from 'react-router';
import { cn } from '../../lib/cn';
import { formatAmount, type Document } from '../../lib/types';
import { CategoryChip, Chevron, Pill } from './Chips';

/** Vignette de la premiere page, ou un rectangle vide de meme gabarit. */
function Thumb({ document, className }: { document: Document; className?: string }) {
    const cover = document.pages?.[0];

    return cover?.thumb_url ? (
        <img
            src={cover.thumb_url}
            alt=""
            loading="lazy"
            className={cn('shrink-0 rounded-[0.4375rem] bg-surface-2 object-cover', className)}
        />
    ) : (
        <div className={cn('shrink-0 rounded-[0.4375rem] bg-surface-2', className)} aria-hidden="true" />
    );
}

/**
 * Etat du document, en pilule. Une seule a la fois : au-dela, la ligne se met a
 * ressembler a un tableau de bord et on ne lit plus rien.
 */
function StatusPill({ document }: { document: Document }) {
    if (!document.is_terminal) return <Pill tone="working">{document.status_label}</Pill>;
    if (document.status === 'failed') return <Pill tone="late">{document.status_label}</Pill>;
    return null;
}

interface DocumentRowProps {
    document: Document;
    /** Index dans la liste : sert au decalage d'apparition. */
    index?: number;
    /** Ce qui identifie la ligne dans son contexte. Dans une categorie on
     *  montre le destinataire ; dans une fiche personne, la categorie. */
    context?: 'category' | 'person' | 'default';
}

export function DocumentRow({ document, index = 0, context = 'default' }: DocumentRowProps) {
    const amount = formatAmount(document.total_amount, document.currency);
    const todos = document.todos_count ?? 0;

    const subtitle =
        context === 'category'
            ? (document.recipient ?? document.issuer)
            : (document.issuer ?? document.recipient);

    return (
        <Link
            to={`/documents/${document.id}`}
            className="pressable flex items-center gap-3.5 px-3.5 py-3 active:bg-surface-2"
            style={{ ['--i' as string]: Math.min(index, 8) }}
        >
            <Thumb document={document} className="h-[3.5625rem] w-11" />

            <div className="min-w-0 flex-1">
                <p className="truncate leading-[1.3125rem] font-semibold">{document.title}</p>
                {subtitle && (
                    <p className="truncate text-[0.875rem] leading-[1.1875rem] text-fg-2">{subtitle}</p>
                )}

                <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <StatusPill document={document} />
                    {amount && <Pill>{amount}</Pill>}
                    {todos > 0 && (
                        <Pill tone="soon">
                            {todos} tache{todos > 1 ? 's' : ''}
                        </Pill>
                    )}
                </div>
            </div>

            {context === 'person' && document.category ? (
                <CategoryChip category={document.category} />
            ) : (
                <Chevron />
            )}
        </Link>
    );
}

export default DocumentRow;
