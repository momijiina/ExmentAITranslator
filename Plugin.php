<?php

namespace App\Plugins\AITranslator;

use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Services\Plugin\PluginPageBase;
use GuzzleHttp\Client;

class Plugin extends PluginPageBase
{
    protected $useCustomOption = true;
    
    /**
     * プラグインページではCSRF検証を無効化
     */
    public $disableSession = false;

    /**
     * カスタム設定フォーム
     */
    public function setCustomOptionForm(&$form)
    {
        $form->password('gemini_api_key', 'Gemini APIキー')
            ->required()
            ->help('Google AI StudioでAPIキーを取得してください: https://aistudio.google.com/app/apikey');
    }

    /**
     * トップページ表示
     */
    public function index()
    {
        $apiKey = $this->plugin->getCustomOption('gemini_api_key');
        $hasApiKey = !empty($apiKey);
        
        $html = $this->generateIndexHtml($hasApiKey, $this->plugin->getFullUrl('translate'));
        
        return $html;
    }

    /**
     * 翻訳実行
     */
    public function translate()
    {
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        try {
            $request = request();
            $sourceText = $request->input('source_text', '');
            $targetLanguage = $request->input('target_language', '日本語');
            $customInstruction = $request->input('custom_instruction', '');

            if (empty($sourceText)) {
                restore_error_handler();
                return response()->json(['error' => '翻訳するテキストを入力してください。'], 400);
            }
            
            // 文字数制限チェック（10,000文字まで）
            $charCount = mb_strlen($sourceText);
            if ($charCount > 10000) {
                restore_error_handler();
                return response()->json(['error' => "テキストが長すぎます（{$charCount}文字）。\n10,000文字以内に収めてください。"], 400);
            }

            // カスタム設定からAPIキーを取得
            $apiKey = $this->plugin->getCustomOption('gemini_api_key');
            if (empty($apiKey)) {
                restore_error_handler();
                return response()->json(['error' => 'Gemini APIキーが設定されていません。プラグイン設定画面で設定してください。'], 400);
            }

            // 長い文章の場合は分割して翻訳（3000文字以上）
            if ($charCount > 3000) {
                $translatedText = $this->translateLongText($sourceText, $targetLanguage, $customInstruction, $apiKey);
            } else {
                $translatedText = $this->translateText($sourceText, $targetLanguage, $customInstruction, $apiKey);
            }

            restore_error_handler();
            
            return response()->json([
                'success' => true,
                'translated_text' => $translatedText,
            ]);

        } catch (\Throwable $e) {
            restore_error_handler();
            \Log::error('Translation error: ' . $e->getMessage());
            \Log::error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            \Log::error($e->getTraceAsString());
            
            // GuzzleHTTPの例外からHTTPステータスコードを取得
            $errorMessage = $e->getMessage();
            $statusCode = 500;
            
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
            }
            
            // 429エラー（レート制限）の場合は分かりやすいメッセージを返す
            if ($statusCode === 429 || strpos($errorMessage, '429') !== false || strpos($errorMessage, 'quota') !== false) {
                return response()->json([
                    'error' => 'Google Gemini APIの利用制限に達しました。\n\n対処方法:\n1. 数分待ってから再度お試しください\n2. 別のAPIキーを使用してください\n3. Google AI Studioで課金プランをご確認ください\n\nhttps://aistudio.google.com/',
                    'error_type' => 'rate_limit'
                ], 429);
            }
            
            // 503エラー（サーバー過負荷）の場合は分かりやすいメッセージを返す
            if ($statusCode === 503 || strpos($errorMessage, '503') !== false || strpos($errorMessage, 'overloaded') !== false) {
                return response()->json([
                    'error' => 'Gemini APIサーバーが混雑しています。\n\n対処方法:\n1. 数分待ってから再度お試しください\n2. 時間帯を変えて試してください',
                    'error_type' => 'server_overload'
                ], 503);
            }
            
            return response()->json([
                'error' => '翻訳処理でエラーが発生しました:\n' . $errorMessage,
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * 長文を分割して翻訳
     */
    private function translateLongText($text, $targetLanguage, $customInstruction, $apiKey)
    {
        // 段落で分割（改行2つ以上）
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $translatedParagraphs = [];
        $currentChunk = '';
        $chunkLimit = 2500; // 1チャンクあたりの文字数制限
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;
            
            // 現在のチャンクに追加できるかチェック
            if (mb_strlen($currentChunk . "\n\n" . $paragraph) <= $chunkLimit) {
                $currentChunk .= ($currentChunk ? "\n\n" : '') . $paragraph;
            } else {
                // 現在のチャンクを翻訳
                if (!empty($currentChunk)) {
                    $translatedParagraphs[] = $this->translateText($currentChunk, $targetLanguage, $customInstruction, $apiKey);
                    sleep(1); // レート制限対策
                }
                $currentChunk = $paragraph;
            }
        }
        
        // 残りのチャンクを翻訳
        if (!empty($currentChunk)) {
            $translatedParagraphs[] = $this->translateText($currentChunk, $targetLanguage, $customInstruction, $apiKey);
        }
        
        return implode("\n\n", $translatedParagraphs);
    }

    /**
     * テキストを翻訳（リトライ機能付き）
     */
    private function translateText($text, $targetLanguage, $customInstruction, $apiKey)
    {
        $maxRetries = 3;
        $retryDelay = 2; // 秒
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                \Log::info("Translation attempt {$attempt}/{$maxRetries}");
                
                $client = new Client();
                $prompt = $this->buildTranslationPrompt($text, $targetLanguage, $customInstruction);
                
                $response = $client->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ],
                    'json' => [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ],
                    ],
                    'timeout' => 60,
                    'connect_timeout' => 10,
                    'http_errors' => true,
                ]);

                $responseBody = $response->getBody()->getContents();
                \Log::info("API Response: " . substr($responseBody, 0, 500));
                
                $result = json_decode($responseBody, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSONパースエラー: ' . json_last_error_msg() . ' (レスポンス: ' . substr($responseBody, 0, 200) . ')');
                }
                
                // APIエラーレスポンスのチェック
                if (isset($result['error'])) {
                    $errorMessage = $result['error']['message'] ?? 'Unknown API error';
                    throw new \Exception('Gemini APIエラー: ' . $errorMessage);
                }
                
                $translatedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (empty($translatedText)) {
                    throw new \Exception('翻訳結果が空です（意味不明なテキストや翻訳不可能な内容の可能性があります）');
                }

                \Log::info("Translation succeeded on attempt {$attempt}");
                return $translatedText;
                
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $lastException = $e;
                $statusCode = 500;
                
                if ($e->hasResponse()) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    \Log::error("HTTP Status: {$statusCode}, Response: {$responseBody}");
                }
                
                \Log::error("Translation request failed on attempt {$attempt}: " . $e->getMessage());
                
                // 503エラーの場合のみリトライ、それ以外は即座に例外をスロー
                if ($statusCode === 503 && $attempt < $maxRetries) {
                    \Log::info("Retrying after {$retryDelay} seconds (503 Service Unavailable)");
                    sleep($retryDelay);
                    $retryDelay *= 2; // 次回は待機時間を倍に（2秒 → 4秒 → 8秒）
                    continue;
                }
                
                // 503以外のエラー、または最後の試行の場合は例外をスロー
                throw $e;
            }
        }
        
        // 全ての試行が失敗した場合
        throw $lastException;
    }

    /**
     * 翻訳プロンプトを構築
     */
    private function buildTranslationPrompt($text, $targetLanguage, $customInstruction)
    {
        $customPart = $customInstruction ? "\n\nAdditional Instructions:\n{$customInstruction}" : '';
        
        return "You are a professional translator.\n"
             . "Translate the following text into {$targetLanguage}.\n\n"
             . "Rules:\n"
             . "1. Maintain the original meaning and nuance\n"
             . "2. Preserve formatting (line breaks, paragraphs, etc.)\n"
             . "3. Keep any special symbols, numbers, or codes as they are\n"
             . "4. Return ONLY the translated text without any explanations{$customPart}\n\n"
             . "Text to translate:\n---\n{$text}\n---";
    }

    /**
     * インデックスページのHTMLを生成
     */
    private function generateIndexHtml($hasApiKey, $translateUrl)
    {
        $csrfToken = csrf_token();
        $warningHtml = !$hasApiKey ? '<div class="alert alert-warning"><strong>注意:</strong> Gemini APIキーが設定されていません。プラグイン設定画面でAPIキーを設定してください。</div>' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{$csrfToken}">
    <title>AI翻訳システム</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 20px auto; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 30px; }
        h1 { margin: 0 0 10px 0; color: #333; font-size: 28px; }
        .subtitle { color: #666; margin-bottom: 30px; font-size: 14px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .translation-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 768px) { .translation-grid { grid-template-columns: 1fr; } }
        .input-panel, .output-panel { display: flex; flex-direction: column; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .panel-title { font-weight: bold; color: #333; font-size: 16px; }
        .char-count { color: #999; font-size: 14px; }
        textarea { width: 100%; min-height: 300px; padding: 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px; line-height: 1.6; resize: vertical; font-family: inherit; }
        textarea:focus { outline: none; border-color: #3c8dbc; }
        .output-text { width: 100%; min-height: 300px; padding: 15px; border: 2px solid #e8f4f8; border-radius: 8px; font-size: 14px; line-height: 1.6; background: #f8fcfe; white-space: pre-wrap; word-wrap: break-word; overflow-y: auto; }
        .controls { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
        .form-group { flex: 1; min-width: 200px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; font-size: 14px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; line-height: 1.6; }
        select.form-control { height: auto; min-height: 40px; }
        .btn { padding: 12px 32px; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 500; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #3c8dbc 0%, #2c7da0 100%); color: white; }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(60, 141, 188, 0.3); }
        .btn-primary:disabled { background: #ccc; cursor: not-allowed; opacity: 0.6; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .icon { margin-right: 4px; }
        #alertContainer { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌐 AI翻訳システム</h1>
        <p class="subtitle">Google Gemini AIを使用した高品質な翻訳（最大10,000文字、3,000文字以上は自動分割）(テスト中のシステムです長いとエラーになります。)</p>
        
        {$warningHtml}

        <div class="controls">
            <div class="form-group">
                <label for="targetLanguage">翻訳先の言語</label>
                <select class="form-control" id="targetLanguage">
                    <option value="英語" selected>英語 (English)</option>
                    <option value="日本語">日本語</option>
                    <option value="中国語（簡体字）">中国語（簡体字）</option>
                    <option value="中国語（繁体字）">中国語（繁体字）</option>
                    <option value="韓国語">韓国語 (한국어)</option>
                    <option value="フランス語">フランス語 (Français)</option>
                    <option value="ドイツ語">ドイツ語 (Deutsch)</option>
                    <option value="スペイン語">スペイン語 (Español)</option>
                    <option value="イタリア語">イタリア語 (Italiano)</option>
                    <option value="ポルトガル語">ポルトガル語 (Português)</option>
                    <option value="ロシア語">ロシア語 (Русский)</option>
                    <option value="アラビア語">アラビア語 (العربية)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="customInstruction">カスタム指示（オプション）</label>
                <input type="text" class="form-control" id="customInstruction" placeholder="例：フォーマルな敬語で">
            </div>
        </div>

        <div class="translation-grid">
            <div class="input-panel">
                <div class="panel-header">
                    <span class="panel-title">📝 原文</span>
                    <span class="char-count" id="sourceCount">0 文字</span>
                </div>
                <textarea id="sourceText" placeholder="翻訳したいテキストを入力してください..."></textarea>
            </div>

            <div class="output-panel">
                <div class="panel-header">
                    <span class="panel-title">✨ 翻訳結果</span>
                    <span class="char-count" id="translatedCount">0 文字</span>
                </div>
                <div class="output-text" id="translatedText">翻訳結果がここに表示されます</div>
            </div>
        </div>

        <div class="action-buttons">
            <button type="button" class="btn btn-primary" id="translateBtn" onclick="startTranslation()">
                <span id="translateBtnText">🚀 翻訳する</span>
                <span class="spinner" id="translateSpinner" style="display: none;"></span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="copyTranslation()">
                📋 コピー
            </button>
            <button type="button" class="btn btn-secondary" onclick="clearAll()">
                🗑️ クリア
            </button>
        </div>

        <div id="alertContainer"></div>
    </div>

    <script>
        const translateUrl = '{$translateUrl}';
        const sourceTextArea = document.getElementById('sourceText');
        const translatedTextDiv = document.getElementById('translatedText');

        // 文字数カウント
        sourceTextArea.addEventListener('input', function() {
            const count = this.value.length;
            const countElement = document.getElementById('sourceCount');
            countElement.textContent = count.toLocaleString() + ' 文字';
            
            // 文字数による警告表示
            if (count > 10000) {
                countElement.style.color = '#dc3545';
                countElement.textContent += ' (制限超過)';
            } else if (count > 8000) {
                countElement.style.color = '#ff6b6b';
                countElement.textContent += ' (制限接近)';
            } else if (count > 3000) {
                countElement.style.color = '#ffa500';
                countElement.textContent += ' (自動分割)';
            } else {
                countElement.style.color = '#999';
            }
        });

        // Enter+Ctrl で翻訳実行
        sourceTextArea.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                startTranslation();
            }
        });

        function startTranslation() {
            const sourceText = sourceTextArea.value.trim();
            const targetLanguage = document.getElementById('targetLanguage').value;
            const customInstruction = document.getElementById('customInstruction').value.trim();
            const translateBtn = document.getElementById('translateBtn');

            if (!sourceText) {
                showAlert('danger', '翻訳するテキストを入力してください。');
                return;
            }
            
            // 文字数制限チェック
            if (sourceText.length > 10000) {
                showAlert('danger', 'テキストが長すぎます（' + sourceText.length.toLocaleString() + '文字）。<br>10,000文字以内に収めてください。');
                return;
            }
            
            // 言語検出：ひらがなまたはカタカナが含まれている場合のみ日本語と判定
            // （漢字のみの場合は中国語の可能性があるため除外）
            const hasJapanese = /[\u3040-\u309F\u30A0-\u30FF]/.test(sourceText);
            if (hasJapanese && targetLanguage === '日本語') {
                showAlert('danger', '日本語のテキストを日本語に翻訳しようとしています。<br>別の言語を選択してください。');
                return;
            }
            
            // 3000文字以上の場合は警告
            if (sourceText.length > 3000) {
                showAlert('warning', 'テキストが長いため、段落ごとに分割して翻訳します（' + sourceText.length.toLocaleString() + '文字）。<br>完了まで時間がかかる場合があります。', false);
            }

            translateBtn.disabled = true;
            document.getElementById('translateBtnText').textContent = '翻訳中...';
            document.getElementById('translateSpinner').style.display = 'inline-block';
            translatedTextDiv.textContent = '翻訳中...';
            translatedTextDiv.style.color = '#999';

            fetch(translateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    source_text: sourceText,
                    target_language: targetLanguage,
                    custom_instruction: customInstruction
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    const errorHtml = data.error.replace(/\\n/g, '<br>');
                    showAlert('danger', errorHtml);
                    translatedTextDiv.textContent = '翻訳に失敗しました';
                    translatedTextDiv.style.color = '#999';
                } else {
                    translatedTextDiv.textContent = data.translated_text;
                    translatedTextDiv.style.color = '#333';
                    const count = data.translated_text.length;
                    document.getElementById('translatedCount').textContent = count.toLocaleString() + ' 文字';
                    showAlert('success', '✅ 翻訳が完了しました！', true);
                }
            })
            .catch(error => {
                console.error('Translation error:', error);
                showAlert('danger', '翻訳に失敗しました: ' + error.message);
                translatedTextDiv.textContent = 'エラーが発生しました';
                translatedTextDiv.style.color = '#999';
            })
            .finally(() => {
                translateBtn.disabled = false;
                document.getElementById('translateBtnText').textContent = '🚀 翻訳する';
                document.getElementById('translateSpinner').style.display = 'none';
            });
        }

        function copyTranslation() {
            const text = translatedTextDiv.textContent;
            if (!text || text === '翻訳結果がここに表示されます' || text === '翻訳中...' || text === 'エラーが発生しました') {
                showAlert('danger', 'コピーする翻訳結果がありません。');
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                showAlert('success', '📋 翻訳結果をクリップボードにコピーしました！', true);
            }).catch(err => {
                showAlert('danger', 'コピーに失敗しました: ' + err.message);
            });
        }

        function clearAll() {
            if (confirm('入力内容と翻訳結果をクリアしますか？')) {
                sourceTextArea.value = '';
                translatedTextDiv.textContent = '翻訳結果がここに表示されます';
                translatedTextDiv.style.color = '#999';
                document.getElementById('sourceCount').textContent = '0 文字';
                document.getElementById('translatedCount').textContent = '0 文字';
                clearAlert();
            }
        }

        function showAlert(type, message, autoClear = false) {
            document.getElementById('alertContainer').innerHTML = 
                '<div class="alert alert-' + type + '">' + message + '</div>';
            if (autoClear) {
                setTimeout(clearAlert, 3000);
            }
        }

        function clearAlert() {
            document.getElementById('alertContainer').innerHTML = '';
        }
    </script>
</body>
</html>
HTML;
    }
}
