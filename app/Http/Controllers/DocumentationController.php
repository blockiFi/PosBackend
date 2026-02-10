<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class DocumentationController extends Controller
{
    public function show($file)
    {
        $filePath = base_path($file . '.md');
        
        if (!File::exists($filePath)) {
            abort(404, 'Documentation not found');
        }
        
        $content = File::get($filePath);
        
        // Convert markdown to HTML using CommonMark with GitHub Flavored Markdown
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        
        $converter = new MarkdownConverter($environment);
        $html = $converter->convert($content);
        
        // Extract title from first # heading
        preg_match('/^#\s+(.+)$/m', $content, $matches);
        $title = $matches[1] ?? str_replace('_', ' ', Str::title($file));
        
        return view('documentation', [
            'title' => $title,
            'content' => $html,
            'file' => $file
        ]);
    }
}
