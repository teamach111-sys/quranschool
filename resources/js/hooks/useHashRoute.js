import { useCallback, useEffect, useState } from 'react';

function readRouteState() {
    const [route = '', query = ''] = window.location.hash.replace(/^#\/?/, '').split('?');

    return {
        route,
        params: Object.fromEntries(new URLSearchParams(query)),
    };
}

/**
 * Tiny hash router so the dashboard shell can swap pages without a reload.
 * Routes look like `/dashboard#/eleves`.
 */
export default function useHashRoute() {
    const [routeState, setRouteState] = useState(readRouteState);

    useEffect(() => {
        const onHashChange = () => setRouteState(readRouteState());
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    const navigate = useCallback((key) => {
        window.location.hash = key ? `/${key}` : '/';
    }, []);

    return { route: routeState.route, routeParams: routeState.params, navigate };
}
