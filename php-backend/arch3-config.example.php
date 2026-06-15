<?php
// Modelo de configuração do backend PHP do Arch3.
// No servidor, a versão real fica em /home1/<user>/arch3-config.php
// (FORA do diretório público arch3.net/), nunca versionada.
return [
    'OPENAI_API_KEY'       => 'sk-proj-...',   // chave secreta (só no servidor)
    'OPENAI_IMAGE_MODEL'   => 'gpt-image-1.5',
    'OPENAI_IMAGE_SIZE'    => '1536x1024',
    'OPENAI_IMAGE_QUALITY' => 'medium',        // low | medium | high
];
