
function Dialog(parentId,callbacks,style)
{
    this.callbacks = callbacks;
    this.style = style;
    this.parent=parentId ? (document.getElementById(parentId) || document.body):
        document.body;
    Dialog.prototype.count = (Dialog.prototype.count) ?
        Dialog.prototype.count+1 : 1;
    this.id = '_dlg' + Dialog.prototype.count;
    this.div = document.createElement("div");
    this.div.id = '_dlg' + Dialog.prototype.count;
    this.div.style.zIndex = 999;
    this.div.setAttribute("class","fmap_dlg");
    this.contentsContainer = document.createElement("div");
    this.div.appendChild(this.contentsContainer);
    var actionsContainer = document.createElement("div");
    actionsContainer.style.textAlign = 'center';
	if(this.callbacks)
	{
		for(k in this.callbacks)
		{
			if(k!="create") 
			{
        		var btn = document.createElement("input");
        		btn.value=k;
        		btn.type="button";
        		btn.id = this.div.id + "_"+k;
        		btn.addEventListener("click", this.callbacks[k]);
        		actionsContainer.appendChild(btn);
			}
		}
    }
    this.div.appendChild(actionsContainer);
    if(style)
        for(var s in style)
            this.div.style[s] = style[s];
}

Dialog.prototype.setContent = function(content)
{
    this.contentsContainer.innerHTML = content;
}

Dialog.prototype.setDOMContent = function(domElement)
{
    while(this.contentsContainer.childNodes.length > 0)
        this.contentsContainer.removeChild(this.contentsContainer.firstChild);
    this.contentsContainer.appendChild(domElement);
}

Dialog.prototype.show = function()
{
    this.parent.appendChild(this.div);
    this.div.style.visibility = 'visible';
}

Dialog.prototype.hide = function()
{
    this.div.style.visibility = 'hidden';
    this.parent.removeChild(this.div);
}

Dialog.prototype.isVisible = function()
{
    return this.div.style.visibility=='visible';
}

Dialog.prototype.setPosition = function(x,y)
{
	this.div.style.position ="absolute";
    this.div.style.left=x;
    this.div.style.top=y;
}

Dialog.prototype.setSize = function(w,h)
{
    this.div.style.width=w;
    this.div.style.height=h;
}
