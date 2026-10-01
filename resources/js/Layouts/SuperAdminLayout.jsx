import WorkspaceLayout from './WorkspaceLayout';

export default function SuperAdminLayout({ title, children }) {
    return <WorkspaceLayout title={title} platform>{children}</WorkspaceLayout>;
}
