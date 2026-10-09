export const createRequestGuard = () => {
    let pending = false;

    return {
        get pending() {
            return pending;
        },
        begin() {
            if (pending) return false;
            pending = true;
            return true;
        },
        end() {
            pending = false;
        },
    };
};

export const releaseFocusWithin = (activeElement, ...containers) => {
    if (!activeElement || !containers.some((container) => container?.contains(activeElement))) {
        return false;
    }

    activeElement.blur();
    return true;
};

export const restoreFocus = (target) => {
    if (!target?.isConnected || target.closest('[aria-hidden="true"]') || target.closest('[inert]')) {
        return false;
    }

    target.focus();
    return true;
};
