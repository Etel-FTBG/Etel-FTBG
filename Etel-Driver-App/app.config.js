require('dotenv').config();

const base = require('./app.base.json').expo;

module.exports = {
  expo: {
    ...base,
    android: {
      ...base.android,
      config: {
        googleMaps: {
          apiKey: process.env.GOOGLE_MAPS_API_KEY || '',
        },
      },
    },
    ios: {
      ...base.ios,
      config: {
        googleMapsApiKey: process.env.GOOGLE_MAPS_API_KEY || '',
      },
    },
    extra: {
      ...base.extra,
      etelApiBaseUrl: process.env.ETEL_API_BASE_URL || base.extra.etelApiBaseUrl,
      eas: {
        projectId: '42a8959a-b67c-4a3c-b95c-630c6f5acb36',
      },
    },
    owner: 'etel-ftbg',
  },
};
