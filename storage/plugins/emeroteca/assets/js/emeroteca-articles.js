// Progressive enhancement: the server validates the relationship as well.
//
// The issue picker used to receive every issue of the Kardex and hide the ones
// of other mastheads client-side — a list that grew without bound with the
// collection. It now asks for the issues of the chosen masthead only. Without
// JavaScript the picker offers "masthead only", which is still a valid choice.
(() => {
    const host = document.getElementById('target-host');
    const issue = document.getElementById('target-issue');
    if (!host || !issue || !issue.dataset.issuesUrl) return;

    const placeholder = issue.options[0];
    let pending = 0;

    /** Clear the issue picker back to its placeholder-only state. */
    const reset = () => {
        issue.replaceChildren(placeholder);
        issue.value = '0';
    };

    /**
     * Fetch and repopulate the issue picker for the currently selected masthead.
     * Tracks a request counter so a slower response for a masthead the operator
     * has since changed away from never overwrites the current option list.
     */
    const load = async () => {
        const request = ++pending;
        reset();
        const testata = Number.parseInt(host.value, 10);
        if (!(testata > 0)) return;
        issue.disabled = true;
        try {
            const url = new URL(issue.dataset.issuesUrl, window.location.origin);
            url.searchParams.set('testata', String(testata));
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            // A slower answer for a masthead the operator has since changed away
            // from must not overwrite the current list.
            if (request !== pending) return;
            for (const item of Array.isArray(data.issues) ? data.issues : []) {
                const option = document.createElement('option');
                option.value = String(item.id);
                option.textContent = String(item.label);
                issue.append(option);
            }
        } catch {
            // Leave "masthead only": the association still works without an issue.
        } finally {
            if (request === pending) issue.disabled = false;
        }
    };

    host.addEventListener('change', load);
    if (host.value && host.value !== '0') load();
})();
