import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
) {
    const targetUrl = toUrl(urlToCheck);

    // Normalize URLs by removing trailing slashes
    const normalizedTarget = targetUrl.replace(/\/$/, '') || '/';
    const normalizedCurrent = currentUrl.replace(/\/$/, '') || '/';

    // Exact match for root/dashboard
    if (normalizedTarget === '/' || normalizedTarget === '/dashboard') {
        return normalizedCurrent === '/' || normalizedCurrent === '/dashboard';
    }

    // For other routes, check if current URL starts with target
    return normalizedCurrent === normalizedTarget || normalizedCurrent.startsWith(normalizedTarget + '/');
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
