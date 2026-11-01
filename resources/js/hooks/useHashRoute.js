import { useCallback, useEffect, useState } from 'react';

function readRoute() {
    return window.location.hash.replace(/^#\/?/, '');
}

/**
 * Tiny hash router so the dashboard shell can swap pages without a reload.
 * Routes look like `/dashboard#/eleves`.
 */
export default function useHashRoute() {
    const [route, setRoute] = useState(readRoute);

    useEffect(() => {
        const onHashChange = () => setRoute(readRoute());
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    const navigate = useCallback((key) => {
        window.location.hash = key ? `/${key}` : '/';
    }, []);

    return { route, navigate };
}
