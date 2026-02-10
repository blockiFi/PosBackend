<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - POS Backend Documentation</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|fira-code:400,500&display=swap" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif; line-height: 1.7; color: #1a202c; background: #f7fafc; }
        .nav { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .nav-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav h1 { font-size: 1.5rem; font-weight: 700; }
        .nav a { color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; transition: all 0.2s; background: rgba(255,255,255,0.1); }
        .nav a:hover { background: rgba(255,255,255,0.25); transform: translateY(-1px); }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .doc-container { background: white; border-radius: 12px; padding: 60px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .doc-content { max-width: 900px; }
        h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 30px; color: #2d3748; border-bottom: 3px solid #667eea; padding-bottom: 15px; }
        h2 { font-size: 2rem; font-weight: 600; margin-top: 50px; margin-bottom: 20px; color: #2d3748; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        h3 { font-size: 1.5rem; font-weight: 600; margin-top: 35px; margin-bottom: 15px; color: #4a5568; }
        h4 { font-size: 1.25rem; font-weight: 600; margin-top: 25px; margin-bottom: 12px; color: #4a5568; }
        h5 { font-size: 1.125rem; font-weight: 600; margin-top: 20px; margin-bottom: 10px; color: #4a5568; }
        p { margin-bottom: 18px; color: #2d3748; line-height: 1.8; }
        ul, ol { margin-left: 30px; margin-bottom: 20px; }
        ul ul, ol ol, ul ol, ol ul { margin-top: 8px; margin-bottom: 8px; }
        li { margin-bottom: 12px; color: #2d3748; line-height: 1.7; }
        li p { margin-bottom: 8px; }
        code { background: #f7fafc; padding: 3px 8px; border-radius: 4px; font-family: 'Fira Code', 'Courier New', monospace; font-size: 0.9em; color: #e53e3e; border: 1px solid #e2e8f0; }
        pre { background: #2d3748; color: #e2e8f0; padding: 24px; border-radius: 8px; overflow-x: auto; margin: 24px 0; line-height: 1.6; border: 1px solid #4a5568; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        pre code { background: none; color: inherit; padding: 0; border: none; font-size: 0.95em; font-family: 'Fira Code', monospace; }
        a { color: #667eea; text-decoration: none; font-weight: 500; border-bottom: 1px solid transparent; transition: all 0.2s; }
        a:hover { border-bottom-color: #667eea; color: #5568d3; }
        strong { color: #2d3748; font-weight: 600; }
        em { font-style: italic; color: #4a5568; }
        blockquote { border-left: 4px solid #667eea; padding-left: 20px; margin: 20px 0; color: #4a5568; font-style: italic; background: #f7fafc; padding: 15px 20px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        th, td { padding: 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #667eea; color: white; font-weight: 600; }
        tr:hover { background: #f7fafc; }
        hr { border: none; border-top: 2px solid #e2e8f0; margin: 40px 0; }
        .back-link { display: inline-flex; align-items: center; color: #667eea; font-weight: 500; margin-bottom: 20px; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .back-link svg { margin-right: 8px; }
        .toc { background: #f7fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #667eea; }
        .toc h3 { margin-top: 0; font-size: 1.25rem; }
        .toc ul { margin-left: 20px; }
        .toc li { margin-bottom: 8px; }
        @media (max-width: 768px) {
            .doc-container { padding: 30px 20px; }
            h1 { font-size: 2rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.25rem; }
            .nav-container { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="nav-container">
            <h1>🏪 POS Backend Documentation</h1>
            <a href="/">← Back to Home</a>
        </div>
    </div>
    
    <div class="container">
        <div class="doc-container">
            <div class="doc-content">
                {!! $content !!}
            </div>
        </div>
    </div>
</body>
</html>
