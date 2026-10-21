import { act } from 'react';
import { createRoot } from 'react-dom/client';
import DataMigration from '../../resources/js/Pages/SuperAdmin/DataMigration.jsx';
import DataMigrationRun from '../../resources/js/Pages/SuperAdmin/DataMigrationRun.jsx';

const pages = { index: DataMigration, run: DataMigrationRun };
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
