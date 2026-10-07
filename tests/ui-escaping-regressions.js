// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const context={};vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function esc('),source.indexOf('function fmtBytes(')),context);
const decode=value=>value.replace(/&(amp|lt|gt|quot|#39);/g,(_,entity)=>({amp:'&',lt:'<',gt:'>',quot:'"','#39':"'"}[entity]));
const names=['Squid Proxy',`Squid "Proxy" & O'Brien <test>`, '" autofocus onfocus="alert(1)', "' onclick='alert(1)", '<img src=x onerror=alert(1)>', 'pool/VM &quot; / disk 08.raw', 'Dév 🐈'];
for(const name of names){
  const encoded=context.esc(name);
  assert.ok(!/[<>"']/.test(encoded),'No raw delimiters may survive HTML escaping');
  assert.equal(decode(encoded),name,'VM/peer/path display and data attribute values must round-trip unchanged');
  const attribute='<input data-vm-name="'+encoded+'" value="Move">';
  assert.equal((attribute.match(/"/g)||[]).length,4,'Name cannot inject additional quoted attributes');
}
assert.equal(context.esc(null),'');assert.equal(context.esc(undefined),'');assert.equal(context.esc(0),'0');
assert.equal(context.esc(`&<>"'`),'&amp;&lt;&gt;&quot;&#39;');
console.log('HTML text and attribute quote escaping regressions passed.');
