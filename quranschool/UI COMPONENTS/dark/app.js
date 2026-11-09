// Set indeterminate state on checkbox demo
  const indeterminateEl = document.getElementById('indeterminateBox');
  if (indeterminateEl) {
    indeterminateEl.indeterminate = true;
  }

  // Demo export action
  const exportBtn = document.getElementById('exportTokensBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const tokens = {
        workspaceCanvas: "#1E2B26",
        surfaceCard: "#24332D",
        primaryEmeraldAccent: "#376757",
        deepForestText: "#E9F0EC",
        borderOutline: "#384C43",
        alertRed: "#E47D70",
        goldWarning: "#D9A441",
        verdantCalm: "#2D3E37"
      };
      const blob = new Blob([JSON.stringify(tokens, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'serene-tahfiz-tokens.json';
      a.click();
      URL.revokeObjectURL(url);
    });
  }

  // Smooth scroll to table demo on click
  const liveDemoBtn = document.getElementById('liveDemoBtn');
  if (liveDemoBtn) {
    liveDemoBtn.addEventListener('click', () => {
      const target = document.querySelector('table');
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }
