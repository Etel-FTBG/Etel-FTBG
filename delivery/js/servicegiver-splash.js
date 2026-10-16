/* Service Giver page: fades out the splash screen. Was an inline script in servicegiver.html. */
(function(){
  setTimeout(function(){
    var el = document.getElementById('etel-master-splash');
    if (!el) return;
    el.style.opacity = '0';
    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 400);
  }, 900);
})();
