import { useEffect, useRef, useState } from 'react';
import { Viewer } from '@photo-sphere-viewer/core';
import { GyroscopePlugin } from '@photo-sphere-viewer/gyroscope-plugin';

const gyroOptions = {
  touchmove: true,
  absolutePosition: false,
  moveMode: 'smooth',
};

export default function PanoramaViewer({ imageUrl }) {
  const containerRef = useRef(null);
  const viewerRef = useRef(null);
  const gyroRef = useRef(null);
  const [gyroEnabled, setGyroEnabled] = useState(false);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const mediaQuery = window.matchMedia('(max-width: 768px)');
    const updateViewport = () => setIsMobile(mediaQuery.matches);

    updateViewport();
    mediaQuery.addEventListener('change', updateViewport);

    return () => mediaQuery.removeEventListener('change', updateViewport);
  }, []);

  useEffect(() => {
    if (!containerRef.current || !imageUrl) return undefined;

    if (viewerRef.current) {
      try {
        viewerRef.current.destroy();
      } catch {
        // Ignore teardown failures during rapid re-renders.
      }
      viewerRef.current = null;
      gyroRef.current = null;
      setGyroEnabled(false);
    }

    const viewer = new Viewer({
      container: containerRef.current,
      panorama: imageUrl,
      caption: '',
      navbar: false,
      loadingImg: null,
      loadingTxt: '',
      defaultZoomLvl: isMobile ? 42 : 30,
      mousewheel: true,
      touchmoveTwoFingers: false,
      moveInertia: true,
      moveSpeed: isMobile ? 0.7 : 1,
      plugins: [[GyroscopePlugin, gyroOptions]],
    });

    viewerRef.current = viewer;
    gyroRef.current = viewer.getPlugin(GyroscopePlugin);

    const enableGyroOnMobile = async () => {
      if (!gyroRef.current || !isMobile || gyroEnabled) return;

      try {
        if (
          'DeviceOrientationEvent' in window &&
          typeof DeviceOrientationEvent.requestPermission === 'function'
        ) {
          const permission = await DeviceOrientationEvent.requestPermission();
          if (permission !== 'granted') return;
        }

        gyroRef.current.start();
        setGyroEnabled(true);
      } catch {
        setGyroEnabled(false);
      }
    };

    if (isMobile) {
      const onFirstInteraction = () => {
        enableGyroOnMobile();
      };

      containerRef.current.addEventListener('pointerdown', onFirstInteraction, { once: true });
      window.setTimeout(onFirstInteraction, 300);
    }

    return () => {
      try {
        viewer.destroy();
      } catch {
        // Ignore teardown failures.
      }
    };
  }, [imageUrl, isMobile, gyroEnabled]);

  const handleGyroClick = async () => {
    if (!gyroRef.current) return;

    try {
      if (
        'DeviceOrientationEvent' in window &&
        typeof DeviceOrientationEvent.requestPermission === 'function'
      ) {
        const permission = await DeviceOrientationEvent.requestPermission();
        if (permission !== 'granted') return;
      }

      gyroRef.current.start();
      setGyroEnabled(true);
    } catch {
      setGyroEnabled(false);
    }
  };

  return (
    <section className="panorama-viewer-shell">
      <div className="panorama-viewer-header">
        <div>
          <p className="eyebrow">360 preview</p>
          <h2>Explore the generated panorama</h2>
        </div>

        <button className="gyro-button" type="button" onClick={handleGyroClick}>
          {isMobile ? (gyroEnabled ? 'Gyroscope on' : 'Enable gyroscope') : 'Drag to explore'}
        </button>
      </div>

      <div ref={containerRef} className="panorama-viewer-canvas" aria-label="360 panorama viewer" />
    </section>
  );
}