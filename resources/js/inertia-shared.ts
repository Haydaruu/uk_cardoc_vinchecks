import AuthLayout from '@/layouts/auth-layout';
import BaseLayout from './layouts/base-layout';

export const appName = import.meta.env.VITE_APP_NAME || 'UkCardoc';

export const title = (pageTitle?: string) =>
    pageTitle ? `${pageTitle} - ${appName}` : appName;

export const defaultLayout = (name: string) =>
    name.startsWith('auth/') ? AuthLayout : BaseLayout;
