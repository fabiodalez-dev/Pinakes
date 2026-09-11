// Progressive enhancement: the server validates the relationship as well.
(() => {
    const host = document.getElementById('target-host');
    const issue = document.getElementById('target-issue');
    if (!host || !issue) return;
    const update = () => {
        for (const option of issue.options) {
            const matches = !option.dataset.testata || option.dataset.testata === host.value;
            option.hidden = !matches;
            option.disabled = !matches;
        }
        if (issue.selectedOptions[0]?.disabled) issue.value = '0';
    };
    host.addEventListener('change', update);
    update();
})();
