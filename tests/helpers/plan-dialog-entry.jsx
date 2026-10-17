import { useState } from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import PlanChangeDialog from '../../resources/js/Components/Platform/PlanChangeDialog.jsx';
import { ConfirmActionDialog, usePlatformApi } from '../../resources/js/Components/Platform/ui.jsx';

function Thrower() {
    throw new Error('boom');
}

function Harness({ subscription, plans, mode }) {
    const api = usePlatformApi();
    const [open, setOpen] = useState(true);

    return <div>
        <p id="page-marker">PAGE-STILL-HERE</p>
        {open && mode === 'throw' && <ConfirmActionDialog title="Throwing dialog" confirmLabel="Go" impact={<Thrower />} onClose={() => setOpen(false)} onConfirm={async () => {}} />}
        {open && mode !== 'throw' && <PlanChangeDialog subscription={subscription} plans={plans} api={api} onClose={() => setOpen(false)} />}
        {api.stepUpDialog}
    </div>;
}

let root = null;

export function mount(element, props) {
    root = createRoot(element);
    act(() => root.render(<Harness {...props} />));
}

export function update(props) {
    act(() => root.render(<Harness {...props} />));
}

export { act };
