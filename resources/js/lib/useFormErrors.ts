import { useState } from 'react';
import { ApiError } from './api';

/** Maps API validation errors onto form fields, plus one general message. */
export function useFormErrors() {
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);

    const capture = (error: unknown) => {
        if (error instanceof ApiError) {
            const mapped: Record<string, string> = {};
            for (const [field, messages] of Object.entries(error.errors)) mapped[field] = messages[0] ?? '';
            setFieldErrors(mapped);
            setMessage(Object.keys(mapped).length ? null : error.message);
        } else {
            setFieldErrors({});
            setMessage('Could not reach the server. Check your connection and try again.');
        }
    };

    const reset = () => {
        setFieldErrors({});
        setMessage(null);
    };

    return { fieldErrors, message, capture, reset };
}
