import { useEffect, useRef } from 'react';

const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Overlay + flex-column shell: header and footer are fixed, only the body scrolls.
export default function ModalShell({ titleId, eyebrow, title, subtitle, closeLabel, onClose, footer, children }) {
    const shellRef = useRef(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        const trigger = window.document.activeElement;
        const previousOverflow = window.document.body.style.overflow;
        window.document.body.style.overflow = 'hidden';
        shellRef.current?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                closeRef.current();
                return;
            }
            if (event.key !== 'Tab' || !shellRef.current) return;
            const items = [...shellRef.current.querySelectorAll(focusableSelector)];
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && (window.document.activeElement === first || window.document.activeElement === shellRef.current)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && window.document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        window.document.addEventListener('keydown', onKeyDown);
        return () => {
            window.document.removeEventListener('keydown', onKeyDown);
            window.document.body.style.overflow = previousOverflow;
            if (trigger && typeof trigger.focus === 'function') trigger.focus();
        };
    }, []);

    return <div className="modal-backdrop statement-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
        <section ref={shellRef} tabIndex={-1} className="modal statement-document-modal" role="dialog" aria-modal="true" aria-labelledby={titleId} onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-header statement-modal-header">
                <div className="statement-modal-heading">
                    <span className="eyebrow">{eyebrow}</span>
                    <h2 id={titleId}>{title}</h2>
                    <p>{subtitle}</p>
                </div>
                <button type="button" className="statement-modal-close" onClick={onClose} aria-label={closeLabel}>×</button>
            </div>
            <div className="statement-modal-body">{children}</div>
            <div className="modal-footer statement-modal-footer">{footer}</div>
        </section>
    </div>;
}
