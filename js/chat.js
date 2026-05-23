jQuery(document).ready(function($) {
    var sessionId = 'session_' + Math.random().toString(36).substr(2, 9);
    var socket = null;
    var userId = localStorage.getItem('worknoon_user_id');
    
    if (!userId) {
        userId = 'user_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('worknoon_user_id', userId);
    }
    
    // Toggle chat window
    $('.chat-toggle').click(function() {
        $('.chat-body').slideToggle();
    });
    
    // Connect to WebSocket (if available)
    if (typeof WebSocket !== 'undefined') {
        connectWebSocket();
    }
    
    function connectWebSocket() {
        try {
            socket = new WebSocket('ws://localhost:5000');
            
            socket.onopen = function() {
                $('#chat-status').text('Connected to support');
                socket.send(JSON.stringify({ type: 'join', userId: userId }));
            };
            
            socket.onmessage = function(event) {
                var data = JSON.parse(event.data);
                if (data.type === 'message') {
                    addMessage(data.text, 'support');
                }
            };
            
            socket.onerror = function() {
                $('#chat-status').text('Connection issue - using fallback');
            };
            
            socket.onclose = function() {
                $('#chat-status').text('Reconnecting...');
                setTimeout(connectWebSocket, 3000);
            };
        } catch(e) {
            $('#chat-status').text('WebSocket not supported');
        }
    }
    
    // Send message
    $('#chat-send').click(function() {
        sendMessage();
    });
    
    $('#chat-input').keypress(function(e) {
        if (e.which === 13) {
            sendMessage();
        }
    });
    
    function sendMessage() {
        var message = $('#chat-input').val().trim();
        if (!message) return;
        
        addMessage(message, 'user');
        
        // Send via AJAX to WordPress
        $.ajax({
            url: worknoon_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'worknoon_send_message',
                message: message,
                session_id: sessionId,
                nonce: worknoon_ajax.nonce
            },
            success: function(response) {
                console.log('Message sent:', response);
            }
        });
        
        // Send via WebSocket if connected
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({
                type: 'message',
                text: message,
                userId: userId
            }));
        }
        
        $('#chat-input').val('');
    }
    
    function addMessage(text, sender) {
        var messageClass = sender === 'user' ? 'user' : 'support';
        var messageHtml = '<div class="message ' + messageClass + '">' + escapeHtml(text) + '</div>';
        $('#chat-messages').append(messageHtml);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
    }
    
    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Load chat history
    $.ajax({
        url: worknoon_ajax.api_url + '/api/messages/conversations',
        type: 'GET',
        success: function(data) {
            if (data && data.length) {
                data.forEach(function(msg) {
                    addMessage(msg.text, msg.sender === userId ? 'user' : 'support');
                });
            }
        }
    });
});