(function(e){e.plugins.Protect=function(a){var d=[],c;c=a.settings.protect||{};c.list||(c.list=[/<(script|noscript|style)[\u0000-\uFFFF]*?<\/(script|noscript|style)>/g]);a.onSetContent.add(function(a,b){e.each(c.list,function(a){b.content=b.content.replace(a,function(a){d.push(a);return"\x3c!-- pro:"+(d.length-1)+" --\x3e"})})});a.onGetContent.add(function(a,b){b.content=b.content.replace(/\x3c!-- pro:([0-9]+) --\x3e/g,function(a,b){return d[parseInt(b)]})})}})(punymce);