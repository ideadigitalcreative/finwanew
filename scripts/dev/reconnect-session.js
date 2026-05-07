const axios = require('axios');

const API_KEY = 'whatsapp_gateway_api_key_123';
const BASE_URL = 'http://localhost:3004';
const SESSION_ID = 'wa_1_6285159205506';

async function reconnect() {
    try {
        console.log(`Reconnecting session ${SESSION_ID}...`);
        const response = await axios.post(
            `${BASE_URL}/sessions/${SESSION_ID}/reconnect`,
            {},
            {
                headers: {
                    'X-API-Key': API_KEY,
                    'Content-Type': 'application/json'
                }
            }
        );
        console.log('Success:', response.data);
    } catch (error) {
        console.error('Error:', error.response ? error.response.data : error.message);
    }
}

reconnect();
