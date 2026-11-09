// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($){
  'use strict';
  window.unmSessionExpired=false;
  function expired(xhr){
    return xhr.status===401||xhr.status===403||(/^\s*(?:<!doctype html|<html)/i.test(xhr.responseText||'')&&/login|password|csrf/i.test(xhr.responseText||''));
  }
  $.ajaxPrefilter(function(options,original,xhr){
    if(String(options.url||'').split('?')[0]!=='/plugins/unmotion/api.php')return;
    if(window.unmSessionExpired){xhr.abort('session-expired');return;}
    xhr.always(function(){
      if(!expired(xhr)||window.unmSessionExpired)return;
      window.unmSessionExpired=true;
      $('<div id="unm-session-expired" role="alert" class="unm-callout unm-callout-warning">').text('Your Unraid session expired or its security token was rejected. unMotion requests have stopped. Reload this page and sign in again; running server jobs continue independently.').prependTo('body');
      $(document).trigger('unmotion:session-expired');
    });
  });
})(jQuery);
