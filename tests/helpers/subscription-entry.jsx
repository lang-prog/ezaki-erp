import { act } from 'react';
import { createRoot } from 'react-dom/client';
import SubscriptionNotice from '../../resources/js/Components/SubscriptionNotice.jsx';
import CompanyLayout from '../../resources/js/Layouts/CompanyLayout.jsx';
import Subscription from '../../resources/js/Pages/Company/Subscription.jsx';

export function mount(element, { mode, ...props }) {
    const root = createRoot(element);
    const tree = mode === 'page' ? <Subscription {...props} />
        : mode === 'layout' ? <CompanyLayout title="Dashboard"><p id="page-content">PAGE-CONTENT</p></CompanyLayout>
            : <SubscriptionNotice {...props} />;
    act(() => root.render(tree));
}

export { act };
