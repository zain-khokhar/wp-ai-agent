const { useState, useEffect } = React;

function App() {
    const [activeTab, setActiveTab] = useState('chat');
    
    // Settings State
    const [baseUrl, setBaseUrl] = useState('http://localhost:11434/v1');
    const [apiKey, setApiKey] = useState('');
    const [saveStatus, setSaveStatus] = useState('');

    // Chat State
    const [chatHistory, setChatHistory] = useState([]);
    const [prompt, setPrompt] = useState('');
    const [isLoading, setIsLoading] = useState(false);

    // Globals provided by wp_localize_script via PHP
    const wpRestUrl = window.bosscodeAI.restUrl;
    const wpNonce = window.bosscodeAI.nonce;

    // Load initial settings on component mount
    useEffect(() => {
        fetch(`${wpRestUrl}/settings`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wpNonce
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.api_base_url) setBaseUrl(data.api_base_url);
            if (data.api_key) setApiKey(data.api_key);
        })
        .catch(err => console.error('Error fetching settings:', err));
    }, []);

    // Handle form submission for Settings
    const handleSaveSettings = async (e) => {
        e.preventDefault();
        setSaveStatus('Saving...');
        
        try {
            const response = await fetch(`${wpRestUrl}/save-settings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce
                },
                body: JSON.stringify({
                    api_base_url: baseUrl,
                    api_key: apiKey
                })
            });
            const data = await response.json();
            if (data.success) {
                setSaveStatus('Saved successfully!');
                setTimeout(() => setSaveStatus(''), 3000);
            } else {
                setSaveStatus('Error saving settings.');
            }
        } catch (error) {
            setSaveStatus('Request failed.');
        }
    };

    // Handle form submission for Chat
    const handleChatSubmit = async (e) => {
        e.preventDefault();
        if (!prompt.trim()) return;

        // Add user prompt to chat UI instantly
        const newHistory = [...chatHistory, { role: 'user', content: prompt }];
        setChatHistory(newHistory);
        setPrompt('');
        setIsLoading(true);

        try {
            // Forward the latest prompt to our WordPress backend
            const response = await fetch(`${wpRestUrl}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce
                },
                body: JSON.stringify({ prompt: newHistory[newHistory.length - 1].content })
            });
            
            const data = await response.json();
            
            if (response.ok && data.success) {
                // Add AI response to chat UI
                setChatHistory(prev => [...prev, { role: 'assistant', content: data.response }]);
            } else {
                // Handle backend or AI API errors
                const errorMessage = data.message || (data.data && data.data.message) || 'Unknown error';
                setChatHistory(prev => [...prev, { role: 'assistant', content: `Error: ${errorMessage}` }]);
            }
        } catch (error) {
            setChatHistory(prev => [...prev, { role: 'assistant', content: 'Connection failed to WordPress backend.' }]);
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div style={{ 
            marginTop: '20px', 
            padding: '20px', 
            maxWidth: '800px', 
            background: '#fff', 
            borderRadius: '8px', 
            boxShadow: '0 1px 3px rgba(0,0,0,0.1)' 
        }}>
            <h1 style={{ marginBottom: '20px', display: 'flex', alignItems: 'center', gap: '10px' }}>
                <span className="dashicons dashicons-superhero"></span>
                BossCode AI Agent
            </h1>
            
            <div style={{ marginBottom: '20px', borderBottom: '1px solid #ddd', paddingBottom: '10px' }}>
                <button 
                    onClick={() => setActiveTab('chat')} 
                    className={`button ${activeTab === 'chat' ? 'button-primary' : ''}`}
                    style={{ marginRight: '10px' }}
                >
                    Agent Chat
                </button>
                <button 
                    onClick={() => setActiveTab('settings')} 
                    className={`button ${activeTab === 'settings' ? 'button-primary' : ''}`}
                >
                    Settings
                </button>
            </div>

            {/* --- SETTINGS TAB --- */}
            {activeTab === 'settings' && (
                <div>
                    <h2>API Settings</h2>
                    <p>Configure the connection to your local or remote LLM (e.g., Ollama, LM Studio).</p>
                    <form onSubmit={handleSaveSettings}>
                        <table className="form-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><label>API Base URL</label></th>
                                    <td>
                                        <input 
                                            type="text" 
                                            value={baseUrl} 
                                            onChange={(e) => setBaseUrl(e.target.value)} 
                                            className="regular-text"
                                            placeholder="http://localhost:11434/v1"
                                            required
                                        />
                                        <p className="description">
                                            For Ollama use: <code>http://localhost:11434/v1</code><br/>
                                            For LM Studio use: <code>http://127.0.0.1:1234/v1</code>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label>API Key (Optional)</label></th>
                                    <td>
                                        <input 
                                            type="password" 
                                            value={apiKey} 
                                            onChange={(e) => setApiKey(e.target.value)} 
                                            className="regular-text"
                                        />
                                        <p className="description">Leave blank if your local model doesn't require authentication.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p className="submit">
                            <button type="submit" className="button button-primary">Save Settings</button>
                            {saveStatus && <span style={{ marginLeft: '10px', color: '#007cba' }}>{saveStatus}</span>}
                        </p>
                    </form>
                </div>
            )}

            {/* --- CHAT TAB --- */}
            {activeTab === 'chat' && (
                <div style={{ display: 'flex', flexDirection: 'column', height: '500px' }}>
                    <div style={{ 
                        flex: 1, 
                        overflowY: 'auto', 
                        border: '1px solid #ddd', 
                        padding: '15px', 
                        marginBottom: '15px', 
                        borderRadius: '4px', 
                        background: '#f0f0f1' 
                    }}>
                        {chatHistory.length === 0 && (
                            <div style={{ textAlign: 'center', marginTop: '40px', color: '#646970' }}>
                                <span className="dashicons dashicons-format-chat" style={{ fontSize: '40px', height: '40px', width: '40px' }}></span>
                                <h2>Welcome to BossCode</h2>
                                <p>Start chatting with your local AI model.</p>
                            </div>
                        )}
                        {chatHistory.map((msg, index) => (
                            <div key={index} style={{ marginBottom: '15px', textAlign: msg.role === 'user' ? 'right' : 'left' }}>
                                <div style={{ 
                                    display: 'inline-block', 
                                    padding: '12px 16px', 
                                    borderRadius: '8px', 
                                    background: msg.role === 'user' ? '#2271b1' : '#fff',
                                    color: msg.role === 'user' ? '#fff' : '#1d2327',
                                    border: msg.role === 'user' ? 'none' : '1px solid #c3c4c7',
                                    maxWidth: '80%',
                                    textAlign: 'left'
                                }}>
                                    <strong style={{ display: 'block', marginBottom: '5px', fontSize: '12px', opacity: 0.8 }}>
                                        {msg.role === 'user' ? 'You' : 'BossCode'}
                                    </strong>
                                    <span style={{ whiteSpace: 'pre-wrap', lineHeight: '1.5' }}>{msg.content}</span>
                                </div>
                            </div>
                        ))}
                        {isLoading && (
                            <div style={{ textAlign: 'left', marginBottom: '15px' }}>
                                <div style={{ 
                                    display: 'inline-block', 
                                    padding: '12px 16px', 
                                    borderRadius: '8px', 
                                    background: '#fff', 
                                    border: '1px solid #c3c4c7',
                                    color: '#646970' 
                                }}>
                                    <em>BossCode is thinking...</em>
                                </div>
                            </div>
                        )}
                    </div>
                    
                    <form onSubmit={handleChatSubmit} style={{ display: 'flex', gap: '10px' }}>
                        <input 
                            type="text" 
                            value={prompt} 
                            onChange={(e) => setPrompt(e.target.value)} 
                            style={{ flex: 1, padding: '10px' }}
                            className="regular-text"
                            placeholder="Ask your agentic IDE something..."
                            disabled={isLoading}
                        />
                        <button type="submit" className="button button-primary" style={{ padding: '0 20px', height: 'auto' }} disabled={isLoading || !prompt.trim()}>
                            Send Message
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
}

// Render React App to the DOM element injected by PHP
const rootElement = document.getElementById('bosscode-ai-app');
if (rootElement) {
    const root = ReactDOM.createRoot(rootElement);
    root.render(<App />);
}
