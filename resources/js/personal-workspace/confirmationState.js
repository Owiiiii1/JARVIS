export function confirmationLifecycleStatus(pending) {
    if (!pending?.id) {
        return null;
    }

    if (pending.status === 'pending' && pending.expires_at) {
        const expires = Date.parse(pending.expires_at);

        if (!Number.isNaN(expires) && expires <= Date.now()) {
            return 'expired';
        }
    }

    return pending.status ?? null;
}

export function isActionableConfirmation(pending) {
    return confirmationLifecycleStatus(pending) === 'pending';
}

export function resolvedConfirmationCopy(pending) {
    const status = confirmationLifecycleStatus(pending);

    if (status === 'cancelled') {
        return 'Действие отменено';
    }

    if (status === 'expired') {
        return 'Истекло';
    }

    if (status === 'executed' || status === 'confirmed') {
        return pending?.tool_name === 'send_gmail_message'
            ? 'Письмо отправлено'
            : 'Действие выполнено';
    }

    return null;
}

export function withConfirmationState(messages, confirmation) {
    if (!confirmation?.id || !Array.isArray(messages)) {
        return messages;
    }

    return messages.map((item) => {
        if (item?.pending_confirmation?.id !== confirmation.id) {
            return item;
        }

        const patch = { id: confirmation.id };

        Object.entries(confirmation).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                patch[key] = value;
            }
        });

        return {
            ...item,
            pending_confirmation: {
                ...item.pending_confirmation,
                ...patch,
            },
        };
    });
}
