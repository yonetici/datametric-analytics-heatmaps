#!/usr/bin/env python3
import re
import os

def minify_css(css_content):
    # Remove block comments
    css_content = re.sub(r'/\*.*?\*/', '', css_content, flags=re.DOTALL)
    # Remove whitespace around delimiters
    css_content = re.sub(r'\s*([\{\};:,])\s*', r'\1', css_content)
    # Remove redundant spaces
    css_content = re.sub(r'\s+', ' ', css_content)
    # Trim
    return css_content.strip()

def minify_js(js_content):
    # Remove multi-line comments
    js_content = re.sub(r'/\*.*?\*/', '', js_content, flags=re.DOTALL)
    # Remove single-line comments (but ignore URL schemas like http:// or https://)
    js_content = re.sub(r'(?<!:)\/\/.*$', '', js_content, flags=re.MULTILINE)
    # Collapse multiple whitespaces/newlines into a single space
    js_content = re.sub(r'\s+', ' ', js_content)
    # Remove whitespace around operators and delimiters
    js_content = re.sub(r'\s*([\{\}\(\)\[\]=\+\-\*\/,;:<>!|&])\s*', r'\1', js_content)
    return js_content.strip()

def process_file(file_path):
    ext = os.path.splitext(file_path)[1]
    if ext == '.min.js' or ext == '.min.css':
        return
        
    print(f"Minifying: {file_path}")
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()
        
    if ext == '.css':
        minified = minify_css(content)
        out_ext = '.min.css'
    elif ext == '.js':
        minified = minify_js(content)
        out_ext = '.min.js'
    else:
        return
        
    out_path = os.path.splitext(file_path)[0] + out_ext
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write(minified)
    print(f"Generated: {out_path} ({len(content)} -> {len(minified)} bytes)")

def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    workspace_root = os.path.dirname(script_dir)

    plugin_dir = "datametric-analytics-heatmaps"
    targets = [
        os.path.join(workspace_root, plugin_dir, "Admin/css/trackly-admin.css"),
        os.path.join(workspace_root, plugin_dir, "Admin/js/trackly-admin.js"),
        os.path.join(workspace_root, plugin_dir, "Public/css/trackly-public.css"),
        os.path.join(workspace_root, plugin_dir, "Public/js/trackly-public.js"),
        os.path.join(workspace_root, plugin_dir, "Public/js/trackly-tracker.js")
    ]
    
    for target in targets:
        if os.path.exists(target):
            process_file(target)
        else:
            print(f"Warning: Target path not found: {target}")

if __name__ == '__main__':
    main()
