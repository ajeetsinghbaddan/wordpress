<?php defined( 'ABSPATH' ) || exit; ?>
<div id="itsc-chatbot" class="itsc-chatbot" role="main" aria-label="IT Services Chatbot">
    <div class="itsc-chatbot-inner">
        <!-- Messages feed -->
        <div class="itsc-messages" id="itsc-messages" role="log" aria-live="polite"></div>

        <!-- Typing indicator -->
        <div class="itsc-typing" id="itsc-typing" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>

        <!-- Options / contact form injected here by JS -->
        <div class="itsc-input-area" id="itsc-input-area"></div>
    </div>
</div>
