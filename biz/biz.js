(function(){
  var $=function(s,r){return (r||document).querySelector(s)},$$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
  document.addEventListener('click',function(e){var b=e.target.closest('[data-confirm]');if(b&&!window.confirm(b.getAttribute('data-confirm')))e.preventDefault();});
  var form=$('#docform');if(!form)return;
  var num=function(v){v=parseFloat(String(v||'').replace(/,/g,''));return isNaN(v)?0:v};
  var cust=$('#cust-pick'),nb=$('#cust-new');
  function togCust(){if(nb)nb.style.display=(cust&&cust.value)?'none':'';}
  if(cust){cust.addEventListener('change',togCust);togCust();}
  var inv=$('#inv-pick');
  if(inv)inv.addEventListener('change',function(){var o=inv.options[inv.selectedIndex];if(!o||!o.value)return;var am=$('#amount'),d=$('#desc');if(am)am.value=o.getAttribute('data-balance');if(d&&!d.value)d.value='Payment for invoice '+o.getAttribute('data-no');
    if(cust){cust.value='';}var nm=$('#cust-name');if(nm)nm.value=o.getAttribute('data-name');togCust();});
  var box=$('#lines');if(!box)return;
  function calc(){var g=0;$$('.ln',box).forEach(function(r){var q=num($('.f-qty',r).value),p=num($('.f-price',r).value),d=num($('.f-disc',r).value),t=num($('.f-tax',r).value);var n=Math.max(0,q*p-d);var tot=n*(1+t/100);g+=tot;$('.lt',r).textContent=tot.toLocaleString(undefined,{maximumFractionDigits:2});});
    $('#grand').textContent=g.toLocaleString(undefined,{maximumFractionDigits:2})+' '+(form.getAttribute('data-currency')||'');}
  function reindex(){$$('.ln',box).forEach(function(r,i){$$('input',r).forEach(function(inp){inp.name=inp.name.replace(/lines\[\d+\]/,'lines['+i+']');});});}
  $('#add-line').addEventListener('click',function(){var rows=$$('.ln',box),c=rows[rows.length-1].cloneNode(true);$$('input',c).forEach(function(i){i.value=i.classList.contains('f-qty')?'1':(i.classList.contains('f-tax')?'0':'');});box.appendChild(c);reindex();calc();});
  box.addEventListener('click',function(e){if(!e.target.classList.contains('rm-line'))return;if($$('.ln',box).length>1){e.target.closest('.ln').remove();reindex();calc();}});
  box.addEventListener('input',function(e){var t=e.target;if(t.classList.contains('f-desc')){var l=$('#itemlist');if(l){$$('option',l).forEach(function(o){if(o.value===t.value){var r=t.closest('.ln');$('.f-price',r).value=o.getAttribute('data-price')||'';$('.f-unit',r).value=o.getAttribute('data-unit')||'';$('.f-tax',r).value=o.getAttribute('data-tax')||'0';}});}}calc();});
  calc();
})();
document.addEventListener('click',function(e){if(e.target.closest('[data-print]'))window.print();});
