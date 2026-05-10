import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './components/App.jsx';

const rootEl = document.getElementById('bosscode-ai-app');
if (rootEl) {
    ReactDOM.createRoot(rootEl).render(<App />);
}