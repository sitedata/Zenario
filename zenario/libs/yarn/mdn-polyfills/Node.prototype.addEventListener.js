!function(){function e(e,t){var n=this,o=function(e){e.target=e.srcElement,e.currentTarget=n,void 0!==t.handleEvent?t.handleEvent(e):t.call(n,e)};if("DOMContentLoaded"==e){var r=function(e){"complete"==document.readyState&&o(e)};if(document.attachEvent("onreadystatechange",r),eventListeners.push({object:this,type:e,listener:t,wrapper:r}),"complete"==document.readyState){var a=new Event;a.srcElement=window,r(a)}}else this.attachEvent("on"+e,o),eventListeners.push({object:this,type:e,listener:t,wrapper:o})}function t(e,t){for(var n=0;n<eventListeners.length;){var o=eventListeners[n];if(o.object==this&&o.type==e&&o.listener==t){"DOMContentLoaded"==e?this.detachEvent("onreadystatechange",o.wrapper):this.detachEvent("on"+e,o.wrapper),eventListeners.splice(n,1);break}++n}}var n;n=window.Node||window.Element,Event.prototype.preventDefault||(Event.prototype.preventDefault=function(){this.returnValue=!1}),Event.prototype.stopPropagation||(Event.prototype.stopPropagation=function(){this.cancelBubble=!0}),n&&n.prototype&&null==n.prototype.addEventListener&&(n.prototype.addEventListener=e,n.prototype.removeEventListener=t,HTMLDocument&&(HTMLDocument.prototype.addEventListener=e,HTMLDocument.prototype.removeEventListener=t),Window&&(Window.prototype.addEventListener=e,Window.prototype.removeEventListener=t))}();