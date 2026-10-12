<style>
    .ai-chat-container {
        background: #ffffff;
        border: 1px solid #d1d3e2;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        display: flex;
        flex-direction: column;
        height: 75vh; 
    }
    .ai-chat-header {
        background-color: #1E2A38;
        color: #ffffff;
        padding: 15px 25px;
        font-weight: 600;
        font-size: 1.1rem;
        border-radius: 4px 4px 0 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .ai-chat-box {
        flex: 1;
        overflow-y: auto;
        padding: 25px;
        background-color: #f8fafc;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .msg-group { display: flex; flex-direction: column; max-width: 85%; }
    .msg-user { align-self: flex-end; }
    .msg-ai { align-self: flex-start; }
    .msg-label { font-size: 0.85rem; font-weight: bold; margin-bottom: 5px; }
    .msg-user .msg-label { text-align: right; color: #7f8c8d; }
    .msg-ai .msg-label { text-align: left; color: #178978; }
    .msg-content { padding: 12px 18px; border-radius: 4px; line-height: 1.5; font-size: 0.95rem; }
    .msg-user .msg-content { background-color: #e8f1f5; border: 1px solid #dcdde1; color: #2c3e50; }
    .msg-ai .msg-content { background-color: #ffffff; border: 1px solid #eef2f5; box-shadow: 0 2px 5px rgba(0,0,0,0.02); color: #333333; }
  
    .ai-chat-footer {
        display: flex;
        padding: 15px;
        background-color: #ffffff;
        border-top: 1px solid #eef2f5;
        border-radius: 0 0 4px 4px;
    }
    .ai-chat-footer input {
        flex: 1; /* Chiếm toàn bộ không gian còn lại để ô nhập dài ra */
        padding: 12px 20px;
        border: 1px solid #dcdde1;
        border-radius: 4px 0 0 4px;
        font-size: 1rem;
        outline: none;
        color: #2c3e50;
        transition: border-color 0.2s;
    }
    .ai-chat-footer input:focus {
        border-color: #178978;
    }
    .ai-chat-footer button {
        padding: 0 40px;
        background-color: #1E2A38;
        color: white;
        border: 1px solid #1E2A38;
        border-radius: 0 4px 4px 0;
        cursor: pointer;
        font-weight: bold;
        font-size: 1rem;
        transition: background-color 0.2s;
    }
    .ai-chat-footer button:hover {
        background-color: #178978;
        border-color: #178978;
    }
</style>

<div class="container-fluid" style="padding: 25px;">
    <div class="ai-chat-container">
        <div class="ai-chat-header">
            <i class="fas fa-robot"></i> Hệ Thống AI Điều Phối Kho
        </div>
        
        <div class="ai-chat-box" id="chat-box">
            <div class="msg-group msg-ai">
                <div class="msg-label">Hệ Thống Trợ Lý</div>
                <div class="msg-content">
                    Xin chào! Tôi là trí tuệ nhân tạo được tích hợp để hỗ trợ quản lý dữ liệu kho. Vui lòng nhập truy vấn của bạn.
                </div>
            </div>
        </div>
        
        <div class="ai-chat-footer">
            <input type="text" id="user-input" placeholder="Nhập câu lệnh hoặc truy vấn của bạn vào đây...">
            <button id="btn-send">Gửi Yêu Cầu</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('#btn-send').click(function() {
        let text = $('#user-input').val();
        if(text.trim() === '') return;

        $('#chat-box').append('<div class="msg-group msg-user"><div class="msg-label">Bạn</div><div class="msg-content">' + text + '</div></div>');
        $('#user-input').val(''); 
        
        let loadingId = 'loading-' + Date.now();
        $('#chat-box').append('<div id="' + loadingId + '" class="msg-group msg-ai"><div class="msg-label">Hệ Thống Trợ Lý</div><div class="msg-content" style="color: #7f8c8d; font-style: italic;">Đang xử lý truy vấn...</div></div>');
        $('#chat-box').scrollTop($('#chat-box')[0].scrollHeight);

        $.ajax({
            url: 'modules/gemini-handler.php',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                text: text
            }),
            success: function(response) {
                $('#' + loadingId).remove();
                let aiReply = 'Xin lỗi, hệ thống chưa nhận được phản hồi hợp lệ từ AI.';
                if (
                    response.candidates &&
                    response.candidates[0] &&
                    response.candidates[0].content &&
                    response.candidates[0].content.parts &&
                    response.candidates[0].content.parts[0] &&
                    response.candidates[0].content.parts[0].text
                ) {
                    aiReply = response.candidates[0].content.parts[0].text;
                }
                aiReply = aiReply.replace(/\n/g, '<br>');
                $('#chat-box').append(
                    '<div class="msg-group msg-ai">' +
                        '<div class="msg-label">Hệ Thống Trợ Lý</div>' +
                        '<div class="msg-content">' + aiReply + '</div>' +
                    '</div>'
                );
                $('#chat-box').scrollTop($('#chat-box')[0].scrollHeight);
            },
            error: function(xhr) {
                $('#' + loadingId).remove();
                console.error('Gemini API status:', xhr.status);
                console.error('Gemini API response:', xhr.responseText);
                let errorMessage = 'Lỗi: Không thể kết nối đến máy chủ AI.';
                try {
                    let errorData = JSON.parse(xhr.responseText);
                    if (errorData.error && errorData.error.message) {
                        errorMessage += '<br><small>Chi tiết: ' + errorData.error.message + '</small>';
                    }
                } catch (e) {
                    errorMessage += '<br><small>Không đọc được chi tiết lỗi từ máy chủ.</small>';
                }
                $('#chat-box').append(
                    '<div class="msg-group msg-ai">' +
                        '<div class="msg-label">Hệ Thống Trợ Lý</div>' + 
                        '<div class="msg-content" style="color: #ff6b6b;">' + errorMessage + '</div>' +
                    '</div>'
                );
            }
        });
    });

    $('#user-input').keypress(function(e) {
        if(e.which == 13) {
            $('#btn-send').click();
        }
    });
});
</script>
