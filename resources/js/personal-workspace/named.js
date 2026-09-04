export function workspaceRoute(surface, name, params) {
    const named = `${surface}.${name}`;

    if (params === undefined) {
        return route(named);
    }

    return route(named, params);
}
