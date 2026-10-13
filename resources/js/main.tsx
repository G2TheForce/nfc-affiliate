import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router';
import App from './App';
import { AuthProvider } from './auth/AuthContext';

const root = document.getElementById('root');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <BrowserRouter>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </BrowserRouter>
        </StrictMode>,
    );
}

// Installable app and offline page; production builds only, so the Vite dev server is unaffected.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}
