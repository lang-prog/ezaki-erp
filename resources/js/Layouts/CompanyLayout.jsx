import WorkspaceLayout from './WorkspaceLayout';

export default function CompanyLayout({ title, children }) {
    return <WorkspaceLayout title={title}>{children}</WorkspaceLayout>;
}
