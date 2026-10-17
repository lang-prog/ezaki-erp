import { act } from 'react';
import { createRoot } from 'react-dom/client';
import Durations from '../../resources/js/Pages/SuperAdmin/Durations.jsx';
import Plans from '../../resources/js/Pages/SuperAdmin/Plans.jsx';

const pages = { durations: Durations, plans: Plans };
let root = null;

export function mount(element, props) {
    root = createRoot(element);
    const Page = pages[props.page];
    act(() => root.render(<Page {...props.data} />));
}

export function update(props) {
    const Page = pages[props.page];
    act(() => root.render(<Page {...props.data} />));
}

export { act };
